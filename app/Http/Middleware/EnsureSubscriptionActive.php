<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ST-Enforce (S1) — the tenant workspace requires a live subscription.
 *
 * A `locked` store (expired trial, canceled, or lapsed beyond grace) is
 * hard-locked and redirected to billing. `grace` and `active` pass through
 * (grace shows a warning banner from the layout). Billing routes are exempt so
 * a locked store can still reach the page where it pays.
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

        // The pay page (and the staff lock screen) must stay reachable while locked.
        if ($request->routeIs('tenant.billing.*', 'tenant.locked')) {
            return $next($request);
        }

        $subscription = $user->tenant?->subscription;
        $state = $subscription?->accessState() ?? 'locked';

        if ($state === 'locked') {
            // SEC-03 — billing is admin-only, so sending staff there is a 403 dead-end.
            // Give them a lock screen that explains it and names who can renew.
            return $user->isStoreAdmin()
                ? redirect()->route('tenant.billing.index')->with('error', $this->lockedMessage($subscription))
                : redirect()->route('tenant.locked');
        }

        return $next($request);
    }

    private function lockedMessage(?Subscription $subscription): string
    {
        return match ($subscription?->status) {
            'trialing' => 'Your free trial has ended. Subscribe to continue using OSMS.',
            'past_due' => 'Your payment is overdue and access is paused. Please renew to continue.',
            default => 'Your subscription is inactive. Subscribe to continue using OSMS.',
        };
    }
}
