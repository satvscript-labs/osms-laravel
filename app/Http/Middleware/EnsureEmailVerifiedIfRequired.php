<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ST-Email (S5) — enforce email verification only when the platform requires it
 * (config('saas.require_email_verification')). Runtime-gated so the flag can be
 * flipped in production once SMTP is confirmed, without a code change, and off
 * locally/in tests. Mirrors Laravel's built-in `verified` middleware behaviour.
 */
class EnsureEmailVerifiedIfRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('saas.require_email_verification')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return $request->expectsJson()
                ? abort(403, 'Your email address is not verified.')
                : redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
