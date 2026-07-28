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

    /** Directly edit status / tier / interval / period end — the escape hatch. */
    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,trialing,past_due,canceled'],
            'tier' => ['required', 'in:basic,pro,enterprise'],
            'interval' => ['nullable', 'in:monthly,yearly'],
            'current_period_end' => ['nullable', 'date'],
            'cancel_at_period_end' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $subscription = $this->subscriptionFor($tenant);

        if (! $subscription) {
            return back()->with('error', 'This store has no subscription.');
        }

        $before = $subscription->only([
            'status', 'tier', 'interval', 'current_period_end', 'cancel_at_period_end',
        ]);

        // A raw edit has no lifecycle equivalent — it IS the escape hatch — but
        // it must still be sticky and audited like every other decision.
        //
        // DATA-06 — PRESERVE any field absent from the post. These previously
        // fell back to null, so submitting without them silently cleared the
        // billing period: status=active + current_period_end=null means
        // accessState() has no boundary to check, i.e. access forever.
        $subscription->update([
            'status' => $validated['status'],
            'tier' => $validated['tier'],
            'interval' => $validated['interval'] ?? $subscription->interval,
            'current_period_end' => $validated['current_period_end'] ?? $subscription->current_period_end,
            'cancel_at_period_end' => $request->boolean('cancel_at_period_end'),
            'manual' => true,
        ]);

        $subscription->applyOverride(
            kind: $validated['status'] === 'canceled' ? 'cancellation' : 'manual_edit',
            until: $validated['status'] === 'canceled' ? null : $subscription->current_period_end,
            reason: $validated['reason'],
        );
        $subscription->save();

        AdminAuditLog::record(
            'subscription.updated',
            "Manually edited {$tenant->store_name}'s subscription",
            $tenant->id,
            [
                'reason' => $validated['reason'],
                'before' => $before,
                'after' => $subscription->refresh()->only([
                    'status', 'tier', 'interval', 'current_period_end', 'cancel_at_period_end',
                ]),
            ],
        );

        return back()->with('status', 'Subscription updated.');
    }

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
