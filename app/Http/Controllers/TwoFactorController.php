<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * SEC-05 — enrolment (in the profile) and the login challenge.
 *
 * The challenge deliberately runs AFTER Laravel has authenticated the password:
 * the session is marked `2fa_pending` and the ForceTwoFactor middleware blocks
 * every other route until the code is verified. That keeps the whole thing out of
 * Breeze's login internals while still gating access.
 */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    // ---------------------------------------------------------------- enrolment

    /** Start enrolment: generate (but do not yet confirm) a secret. */
    public function setup(Request $request): View
    {
        $user = $request->user();

        // Reuse an unconfirmed secret so a page refresh doesn't invalidate the QR
        // the user is halfway through scanning.
        if (! $user->two_factor_secret || $user->hasTwoFactorEnabled()) {
            $user->forceFill([
                'two_factor_secret' => $this->twoFactor->generateSecret(),
                'two_factor_confirmed_at' => null,
            ])->save();
        }

        return view('profile.two-factor', [
            'uri' => $this->twoFactor->provisioningUri($user, $user->two_factor_secret),
            'secret' => $user->two_factor_secret,
        ]);
    }

    /** Confirm enrolment by proving the authenticator produces a valid code. */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->two_factor_secret || ! $this->twoFactor->verify($user->two_factor_secret, $request->string('code'))) {
            throw ValidationException::withMessages([
                'code' => 'That code is not valid. Check your authenticator app and try again.',
            ]);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();

        // The challenge is satisfied for this session by definition.
        $request->session()->forget('2fa_pending');

        AdminAuditLog::record('user.2fa_enabled', "Two-factor enabled for {$user->email}");

        return redirect()->route('profile.edit')
            ->with('recovery_codes', $codes)
            ->with('status', 'Two-factor authentication is on. Save your recovery codes now — they are shown only once.');
    }

    /** Turn 2FA off (password-confirmed by the route middleware). */
    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->mustSetUpTwoFactor()) {
            return back()->with('error', 'Two-factor is required for superadmin accounts and cannot be turned off.');
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AdminAuditLog::record('user.2fa_disabled', "Two-factor disabled for {$user->email}");

        return back()->with('status', 'Two-factor authentication is off.');
    }

    // ---------------------------------------------------------------- challenge

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('2fa_pending')) {
            return redirect()->intended('/');
        }

        return view('auth.two-factor-challenge');
    }

    /** Verify a TOTP code — or a single-use recovery code. */
    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = $request->user();
        $code = (string) $request->string('code');

        if ($user->two_factor_secret && $this->twoFactor->verify($user->two_factor_secret, $code)) {
            return $this->pass($request);
        }

        // Recovery path — consumes the code so it cannot be replayed.
        $remaining = $this->twoFactor->consumeRecoveryCode($user, $code);
        if ($remaining !== null) {
            $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();
            AdminAuditLog::record('user.2fa_recovery_used', "Recovery code used for {$user->email}");

            return $this->pass($request)
                ->with('status', 'Recovery code accepted. ' . count($remaining) . ' remaining.');
        }

        throw ValidationException::withMessages(['code' => 'That code is not valid.']);
    }

    private function pass(Request $request): RedirectResponse
    {
        $request->session()->forget('2fa_pending');
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /** Abandon the challenge — log out rather than leave a half-authed session. */
    public function cancel(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
