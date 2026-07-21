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
        $subscription->update([
            'status' => $validated['status'],
            'tier' => $validated['tier'],
            'interval' => $validated['interval'] ?? $subscription->interval,
            'current_period_end' => $validated['current_period_end'] ?? $subscription->current_period_end,
            'cancel_at_period_end' => (bool) ($request->boolean('cancel_at_period_end')),
            'manual' => true,
        ]);

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

        $subscription->update([
            'status' => 'trialing',
            'current_period_end' => $base->copy()->addDays((int) $validated['days']),
            'cancel_at_period_end' => false,
            'manual' => true,
        ]);

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

        $subscription->update([
            'status' => 'active',
            'interval' => $validated['interval'],
            'current_period_end' => Carbon::today($this->tz())->addMonths((int) $validated['months']),
            'cancel_at_period_end' => false,
            'manual' => true,
        ]);

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

        $subscription->update([
            'status' => 'canceled',
            'cancel_at_period_end' => false,
            'manual' => true,
        ]);

        AdminAuditLog::record(
            'subscription.canceled',
            "Manually canceled {$tenant->store_name}'s subscription",
            $tenant->id,
            ['before' => $before, 'after' => $this->snapshot($tenant->refresh()), 'razorpay' => $razorpayNote],
        );

        return back()->with('status', 'Subscription canceled.' . ($razorpayNote ? " ($razorpayNote)" : ''));
    }
}
