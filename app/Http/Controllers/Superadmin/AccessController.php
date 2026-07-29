<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use App\Services\CredentialIssuer;
use App\Services\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * P5 / REQ-7 — the two levers that act on a person rather than on money:
 * matrix row 14 (re-issue credentials) and "view as store".
 *
 * Both are gated by `superadmin` + `password.confirm`, and both refuse to touch
 * another operator's account — a panel that can reset a peer's password or wear
 * their identity contains its own privilege-escalation path.
 */
class AccessController extends Controller
{
    public function __construct(
        private readonly CredentialIssuer $credentials,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * Row 14 — hand a locked-out owner a new password.
     *
     * Why this exists at all when a self-serve reset email exists: the reset
     * link goes to an address the customer may have lost access to, or typed
     * wrong at signup, or that silently bounces. The operator is on the phone
     * with them; the fastest correct answer is to read them a password.
     */
    public function reissue(Request $request, Account $account, User $user): RedirectResponse
    {
        // The user must belong to a store under THIS customer. A valid id from
        // another account must not be reachable by editing the URL.
        abort_unless($this->belongsToAccount($user, $account), 404);
        abort_if($user->isSuperadmin(), 403, 'Operator passwords are not re-issued from here.');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $password = $this->credentials->reissue($user, $validated['reason']);

        return back()
            ->with('credential', [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $password,
                'store' => $user->tenant?->store_name,
            ])
            ->with('status', 'New password issued. Read it out now — it cannot be shown again.');
    }

    /** Begin a read-only "view as store" session. */
    public function impersonate(Request $request, Account $account, User $user): RedirectResponse
    {
        abort_unless($this->belongsToAccount($user, $account), 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->impersonation->start($request, $user, $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $minutes = (int) config('saas.impersonation_minutes', 30);

        return redirect()->route('tenant.dashboard')
            ->with('status', "You are viewing this store read-only for {$minutes} minutes. Nothing you do can change their data.");
    }

    /**
     * Leave the session.
     *
     * Routed OUTSIDE the superadmin group on purpose: during impersonation the
     * signed-in user IS the shopkeeper, so `EnsureSuperadmin` would refuse the
     * one request whose entire job is to give the operator their seat back.
     */
    public function stopImpersonating(Request $request): RedirectResponse
    {
        $admin = $this->impersonation->stop($request, 'operator');

        if (! $admin) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('superadmin.dashboard')
            ->with('status', 'You are back in your own account.');
    }

    private function belongsToAccount(User $user, Account $account): bool
    {
        return $user->tenant_id !== null
            && $account->stores()->whereKey($user->tenant_id)->exists();
    }
}
