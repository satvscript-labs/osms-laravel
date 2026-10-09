<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Tenant;
use App\Services\BillingService;
use App\Services\SubscriptionLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * LEGACY store-scoped subscription control (ST-Admin / S11).
 *
 * Superseded by the account-first panel: the Customer 360's action set
 * (`Superadmin\AccountActionController`) does everything here and more. These
 * screens stay reachable at `/superadmin/legacy/stores/*` per decision E3 —
 * out of the nav, deleted only once nothing depends on them.
 *
 * AUD-07 — every mutation now DELEGATES to `SubscriptionLifecycle` rather than
 * mutating the model itself, and every one requires a reason. Previously this
 * was a second, weaker path: it could write an override with a null reason,
 * because the reason gate lived in the new controller instead of in the service.
 *
 * Playbook §3.4: one service, every door. Two code paths that both move money
 * WILL diverge; the only question is when, and how expensively.
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionLifecycle $lifecycle) {}

    /**
     * The subscription governing this store — resolved via its ACCOUNT.
     *
     * AUD-02 — a second branch has no subscription row of its own.
     */
    private function subscriptionFor(Tenant $tenant)
    {
        return $tenant->governingSubscription();
    }

    /** Run a lifecycle action, turning a rejection into a flash message. */
    private function run(Tenant $tenant, string $action, array $input, string $ok): RedirectResponse
    {
        $subscription = $this->subscriptionFor($tenant);

        if (! $subscription) {
            return back()->with('error', 'This store has no subscription.');
        }

        try {
            $this->lifecycle->commit($subscription, $action, $input);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $ok);
    }

    /*
     * feat-billing P.3 - there used to be an `update()` here: a raw "edit status / tier /
     * interval / period end" form that assigned those fields directly. Removed. It was the
     * one path that wrote entitlement without going through SubscriptionLifecycle
     * (CLAUDE.md billing rule 1), and every lever it offered exists on the Customer 360,
     * previewed, reason-gated and audited. The three actions below stay: each is a thin
     * wrapper over the lifecycle, and `cancel()` is currently the only operator path that
     * also cancels the Razorpay mandate. They retire with the legacy screens once that
     * behaviour lives inside the lifecycle itself (phase E3).
     */

    /** Grant or extend a free trial by N days. */
    public function extendTrial(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->run($tenant, 'extend', $validated, "Trial extended by {$validated['days']} days.");
    }

    /** Comp N months of paid access without any payment. */
    public function activate(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'months' => ['required', 'integer', 'min:1', 'max:60'],
            'interval' => ['required', 'in:monthly,yearly'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->run($tenant, 'comp', $validated, "Granted {$validated['months']} months of access.");
    }

    /** Cancel access. Also best-effort cancels the live Razorpay subscription. */
    public function cancel(Request $request, Tenant $tenant, BillingService $billing): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $subscription = $this->subscriptionFor($tenant);
        $razorpayNote = null;

        // Prevent the double-charge loophole: if a live Razorpay subscription
        // exists, cancel it too, so we don't keep billing a store we have
        // cancelled locally.
        if ($subscription?->razorpay_subscription_id && $billing->isConfigured()) {
            try {
                $billing->cancelSubscription($subscription->razorpay_subscription_id);
                $razorpayNote = 'Razorpay subscription canceled at cycle end.';
            } catch (\Throwable $e) {
                $razorpayNote = 'Razorpay cancel failed: ' . $e->getMessage();
            }
        }

        $result = $this->run(
            $tenant,
            'cancel',
            $validated,
            'Subscription canceled.' . ($razorpayNote ? " ($razorpayNote)" : ''),
        );

        if ($razorpayNote) {
            AdminAuditLog::record('subscription.razorpay_cancel', $razorpayNote, $tenant->id, [
                'reason' => $validated['reason'],
            ]);
        }

        return $result;
    }
}
