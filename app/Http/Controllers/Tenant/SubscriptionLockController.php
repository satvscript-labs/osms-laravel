<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SEC-03 / UX-07 — the screen a store sees when it cannot use the workspace.
 *
 * ISS-01 — this used to be the second half of an infinite redirect loop.
 *
 * It early-returned to the dashboard whenever `$subscription->hasAccess()` was
 * true. For a CLOSED store that is true — closure lives on the tenant and
 * leaves the subscription untouched and perfectly active — so this bounced them
 * to the dashboard, the middleware bounced them back here, and the browser gave
 * up with ERR_TOO_MANY_REDIRECTS. The customer's entire product was a Chrome
 * error page.
 *
 * The lock reason now comes from ONE place (`Tenant::accessDenialReason()`) and
 * this screen never bounces a store whose access is genuinely denied.
 *
 * Deliberately a controller (not a closure route) so `route:cache` keeps working.
 */
class SubscriptionLockController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;
        $reason = $tenant?->accessDenialReason();

        // Genuinely fine again — don't strand them here.
        if (! $tenant || $reason === null) {
            return redirect()->route('tenant.dashboard');
        }

        // Admins go to the pay page ONLY when paying would lift the block. For
        // an operator suspension, a cancellation or a closure it would not, and
        // sending them there is what produced the loop.
        if ($user->isStoreAdmin() && $tenant->lockIsSelfResolvable()) {
            return redirect()->route('tenant.billing.index');
        }

        return view('tenant.locked', [
            'tenant' => $tenant,
            'subscription' => $tenant->governingSubscription(),
            'reason' => $reason,
            'selfResolvable' => $tenant->lockIsSelfResolvable(),
            'supportEmail' => config('saas.support_email'),
            'admins' => $tenant->users()
                ->where('role', 'store_admin')->orderBy('name')->get(['name', 'email']),
        ]);
    }
}
