<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ST-Enforce (S1) — the tenant workspace requires a live subscription.
 *
 * A locked store is redirected; `grace` and `active` pass through (grace shows
 * a warning banner from the layout).
 *
 * ISS-01 — WHERE it redirects and WHAT it says both depend on
 * `Tenant::accessDenialReason()`, because those two questions have more than
 * two answers. A store the operator suspended cannot pay its way out, so it
 * must not be shown a checkout; a closed store must not be shown one either.
 */
class EnsureSubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Superadmins operate outside tenant billing entirely.
        if (! $user || $user->isSuperadmin()) {
            return $next($request);
        }

        $tenant = $user->tenant;

        if (! $tenant) {
            return $next($request);
        }

        $reason = $tenant->accessDenialReason();

        if ($reason === null) {
            return $next($request);
        }

        // The lock screen must ALWAYS be reachable, or a store whose block
        // cannot be lifted by paying has nowhere at all to land (ISS-01: this
        // is half of the redirect loop a closed store used to hit).
        if ($request->routeIs('tenant.locked')) {
            return $next($request);
        }

        /*
         * ISS-01 — the pay page is exempt only when paying would actually help.
         *
         * It used to be exempt unconditionally, so a store the operator had
         * SUSPENDED landed on a live checkout and was told to renew. They were
         * already paid up: no payment could lift that block, and taking one
         * would have created a refund conversation. The same is true of a
         * closed store and an operator cancellation.
         */
        if ($request->routeIs('tenant.billing.*')) {
            return $tenant->lockIsSelfResolvable()
                ? $next($request)
                : redirect()->route('tenant.locked');
        }

        // SEC-03 — billing is admin-only, so sending staff there is a 403
        // dead-end. Admins go to the pay page only when there is something
        // they can pay for; everyone else gets the screen that explains it.
        return $user->isStoreAdmin() && $tenant->lockIsSelfResolvable()
            ? redirect()->route('tenant.billing.index')->with('error', $this->lockedMessage($reason))
            : redirect()->route('tenant.locked');
    }

    /** Say what is actually true, and never invite a payment that cannot help. */
    private function lockedMessage(string $reason): string
    {
        return match ($reason) {
            'trial_ended' => 'Your free trial has ended. Subscribe to continue using OSMS.',
            'payment_overdue' => 'Your payment is overdue and access is paused. Please renew to continue.',
            'cancelled' => 'Your subscription was cancelled. Subscribe again to continue using OSMS.',
            default => 'Your subscription is inactive. Subscribe to continue using OSMS.',
        };
    }
}
