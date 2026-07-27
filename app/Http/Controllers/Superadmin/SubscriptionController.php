<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Tenant;
use App\Services\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ST-Admin (S11) — manual subscription control, bypassing Razorpay entirely.
 *
 * Every method here mutates money-affecting state, so the routes are gated by
 * `password.confirm` (recent re-auth) on top of the superadmin guard, and each
 * action writes an immutable audit record with the before/after snapshot.
 */
class SubscriptionController extends Controller
{
    private function tz(): string
    {
        return config('billing.timezone', 'Asia/Kolkata');
    }

    /** Snapshot the fields we audit, for a clean before/after diff. */
    private function snapshot(Tenant $tenant): array
    {
        $s = $tenant->subscription;

        return [
            'status' => $s?->status,
            'tier' => $s?->tier,
            'interval' => $s?->interval,
            'current_period_end' => $s?->current_period_end?->toDateString(),
            'cancel_at_period_end' => (bool) $s?->cancel_at_period_end,
            'manual' => (bool) $s?->manual,
            // BUG-P01 — the override is now part of the commercial state, so a
            // before/after diff that omitted it would hide why a webhook was ignored.
            'override_kind' => $s?->override_kind,
            'override_until' => $s?->override_until?->toDateString(),
        ];
    }

    /** Directly edit the subscription record. */
    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,trialing,past_due,canceled'],
            'tier' => ['required', 'in:basic,pro,enterprise'],
            'interval' => ['nullable', 'in:monthly,yearly'],
            'current_period_end' => ['nullable', 'date'],
            'cancel_at_period_end' => ['nullable', 'boolean'],
        ]);

        $subscription = $tenant->subscription;
        $before = $this->snapshot($tenant);

        // DATA-06 — PRESERVE the existing value when a field is absent from the post.
        // Previously these fell back to null, so submitting the form without them
        // silently cleared the billing period: status=active + current_period_end=null
        // means accessState() has no boundary to check, i.e. access forever.
        $subscription->fill([
            'status' => $validated['status'],
            'tier' => $validated['tier'],
            'interval' => $validated['interval'] ?? $subscription->interval,
            'current_period_end' => $validated['current_period_end'] ?? $subscription->current_period_end,
            'cancel_at_period_end' => (bool) ($request->boolean('cancel_at_period_end')),
            'manual' => true,
        ]);

        // BUG-P01 — a hand-edited subscription must survive the next webhook. A
        // cancellation is indefinite (null `until`); anything else holds until the
        // period the operator set.
        $subscription->applyOverride(
            kind: $validated['status'] === 'canceled' ? 'cancellation' : 'manual_edit',
            until: $validated['status'] === 'canceled'
                ? null
                : ($subscription->current_period_end
                    ? Carbon::parse($subscription->current_period_end->toDateString(), $this->tz())
                    : null),
            reason: $request->input('reason'),
        );

        $subscription->save();

        AdminAuditLog::record(
            'subscription.updated',
            "Manually edited {$tenant->store_name}'s subscription",
            $tenant->id,
            ['before' => $before, 'after' => $this->snapshot($tenant->refresh())],
        );

        return back()->with('status', 'Subscription updated.');
    }

    /** Grant or extend a free trial by N days. */
    public function extendTrial(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $subscription = $tenant->subscription;
        $before = $this->snapshot($tenant);

        // Extend from the later of today or the existing end, so an already-active
        // trial is lengthened rather than shortened.
        $base = $subscription->current_period_end
            ? Carbon::parse($subscription->current_period_end->toDateString(), $this->tz())
            : Carbon::today($this->tz());
        $base = $base->max(Carbon::today($this->tz()));

        $newEnd = $base->copy()->addDays((int) $validated['days']);

        $subscription->fill([
            'status' => 'trialing',
            'current_period_end' => $newEnd,
            'cancel_at_period_end' => false,
            'manual' => true,
        ]);

        // BUG-P01 — hold the granted window against any incoming webhook.
        $subscription->applyOverride(
            kind: 'extension',
            until: $newEnd,
            reason: $request->input('reason'),
        );

        $subscription->save();

        AdminAuditLog::record(
            'subscription.trial_extended',
            "Extended {$tenant->store_name}'s trial by {$validated['days']} days",
            $tenant->id,
            ['days' => (int) $validated['days'], 'before' => $before, 'after' => $this->snapshot($tenant->refresh())],
        );

        return back()->with('status', "Trial extended by {$validated['days']} days.");
    }

    /** Comp a paid subscription for N months without any payment. */
    public function activate(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'months' => ['required', 'integer', 'min:1', 'max:60'],
            'interval' => ['required', 'in:monthly,yearly'],
        ]);

        $subscription = $tenant->subscription;
        $before = $this->snapshot($tenant);

        $newEnd = Carbon::today($this->tz())->addMonths((int) $validated['months']);

        $subscription->fill([
            'status' => 'active',
            'interval' => $validated['interval'],
            'current_period_end' => $newEnd,
            'cancel_at_period_end' => false,
            'manual' => true,
        ]);

        // BUG-P01 — this is the exact case that was silently destroyed: a comp on a
        // store with a live Razorpay mandate. The grant now outlives the next charge.
        $subscription->applyOverride(
            kind: 'comp',
            until: $newEnd,
            reason: $request->input('reason'),
        );

        $subscription->save();

        AdminAuditLog::record(
            'subscription.comped',
            "Granted {$tenant->store_name} {$validated['months']} months of free access",
            $tenant->id,
            ['months' => (int) $validated['months'], 'before' => $before, 'after' => $this->snapshot($tenant->refresh())],
        );

        return back()->with('status', "Granted {$validated['months']} months of access.");
    }

    /** Cancel access immediately. Also best-effort cancels the live Razorpay sub. */
    public function cancel(Request $request, Tenant $tenant, BillingService $billing): RedirectResponse
    {
        $subscription = $tenant->subscription;
        $before = $this->snapshot($tenant);
        $razorpayNote = null;

        // Prevent the double-charge loophole: if a live Razorpay sub exists, cancel
        // it too so we don't keep billing a store we've locally canceled.
        if ($subscription->razorpay_subscription_id && $billing->isConfigured()) {
            try {
                $billing->cancelSubscription($subscription->razorpay_subscription_id);
                $razorpayNote = 'Razorpay subscription canceled at cycle end.';
            } catch (\Throwable $e) {
                $razorpayNote = 'Razorpay cancel failed: ' . $e->getMessage();
            }
        }

        $subscription->fill([
            'status' => 'canceled',
            'cancel_at_period_end' => false,
            'manual' => true,
        ]);

        // BUG-P01 — a cancellation is INDEFINITE (null `until`). Without this, a
        // `subscription.charged` webhook from the still-settling mandate would flip
        // the store straight back to active.
        $subscription->applyOverride(
            kind: 'cancellation',
            until: null,
            reason: $request->input('reason'),
        );

        $subscription->save();

        AdminAuditLog::record(
            'subscription.canceled',
            "Manually canceled {$tenant->store_name}'s subscription",
            $tenant->id,
            ['before' => $before, 'after' => $this->snapshot($tenant->refresh()), 'razorpay' => $razorpayNote],
        );

        return back()->with('status', 'Subscription canceled.' . ($razorpayNote ? " ($razorpayNote)" : ''));
    }
}
