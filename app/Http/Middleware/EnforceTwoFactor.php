<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-05 — gate every authenticated route behind the second factor.
 *
 * Two jobs:
 *  1. `2fa_pending` in the session means the password was accepted but the code
 *     has not been — hold the user on the challenge screen.
 *  2. If the account is REQUIRED to have 2FA (superadmin + config flag) but has
 *     not enrolled, push them into setup.
 *
 * The 2FA routes themselves and logout are exempt, or the user would be trapped.
 */
class EnforceTwoFactor
{
    /** Routes that must stay reachable while the challenge is outstanding. */
    private const EXEMPT = [
        'two-factor.challenge',
        'two-factor.verify',
        'two-factor.cancel',
        'two-factor.setup',
        'two-factor.confirm',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $this->isExempt($request)) {
            return $next($request);
        }

        if ($request->session()->get('2fa_pending')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Two-factor verification required.'], 423)
                : redirect()->route('two-factor.challenge');
        }

        if ($user->mustSetUpTwoFactor()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Two-factor setup required.'], 423)
                : redirect()->route('two-factor.setup')
                    ->with('error', 'Two-factor authentication is required for this account. Set it up to continue.');
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && in_array($name, self::EXEMPT, true);
    }
}
