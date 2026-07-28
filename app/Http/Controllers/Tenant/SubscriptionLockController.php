<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SEC-03 / UX-07 — the lock screen a NON-admin sees when their store's subscription
 * has lapsed. Billing is admin-only, so redirecting staff there produced a bare 403
 * dead-end at exactly the moment the store is deciding whether to pay. This explains
 * the situation and tells them who can fix it.
 *
 * Deliberately a controller (not a closure route) so `route:cache` keeps working.
 */
class SubscriptionLockController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;
        // AUD-02 — resolve via the ACCOUNT; a second branch has no row of its own.
        $subscription = $tenant?->governingSubscription();

        // If access is fine again (or they're an admin who can actually pay), don't
        // strand them here.
        if ($subscription?->hasAccess()) {
            return redirect()->route('tenant.dashboard');
        }

        if ($user->isStoreAdmin()) {
            return redirect()->route('tenant.billing.index');
        }

        return view('tenant.locked', [
            'tenant' => $tenant,
            'subscription' => $subscription,
            'admins' => $tenant
                ? $tenant->users()->where('role', 'store_admin')->orderBy('name')->get(['name', 'email'])
                : collect(),
        ]);
    }
}
