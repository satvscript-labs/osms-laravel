<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\BillingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class BillingController extends Controller
{
    /**
     * AUD-02 — the subscription that governs THIS store.
     *
     * Money lives on the ACCOUNT (REQ-12), so a store is covered by its payer's
     * subscription. `Subscription::first()` was tenant-scoped, which silently
     * returned NULL for any store that is not its account's original one — a
     * second branch would have seen a billing page with no plan, no renewal
     * date and no invoices. Falls back to the store's own row for the
     * pre-backfill transition, so behaviour is identical before and after.
     */
    private function subscription(Request $request): ?Subscription
    {
        return $request->user()?->tenant?->governingSubscription();
    }

    /**
     * P4 / matrix row 15 — supervised mode.
     *
     * When a customer is supervised (their own flag, or the platform-wide
     * switch) every payment goes through the operator, so the self-serve
     * checkout is closed for them.
     *
     * ⚠ Enforced HERE, server-side, not merely hidden in the view. A hidden
     * button is a suggestion; this is the rule. Same lesson as the shared-phone
     * consent gap, where the UI hid an action the server still accepted.
     *
     * Constraint C1 — self-serve must never be degraded — is respected by the
     * default: supervision is OFF unless an operator deliberately turns it on.
     */
    private function supervisionBlock(Request $request): ?string
    {
        $account = $request->user()?->tenant?->account;

        if (! $account?->isSupervised()) {
            return null;
        }

        return $account->supervisionReason()
            ?? 'Your account is managed directly by our team. Please contact us to make changes.';
    }

    public function index(Request $request, BillingService $billing): View
    {
        $subscription = $this->subscription($request);

        // Single-plan launch: only the Basic plan is offered.
        $plans = ['basic' => config('billing.plans.basic')];

        $invoices = SubscriptionInvoice::latest('paid_at')->get();

        return view('tenant.billing.index', [
            'subscription' => $subscription,
            'supervised' => $this->supervisionBlock($request),
            'plans' => $plans,
            'invoices' => $invoices,
            'configured' => $billing->isConfigured(),
        ]);
    }

    public function subscribe(Request $request, BillingService $billing): RedirectResponse|View
    {
        if ($blocked = $this->supervisionBlock($request)) {
            return back()->with('error', $blocked);
        }

        $validated = $request->validate([
            'tier' => ['required', 'in:basic'],
            'interval' => ['required', 'in:monthly,yearly'],
        ]);

        if (! $billing->isConfigured()) {
            return back()->with('error', 'Online payments are not configured yet. Please contact support.');
        }

        // Block only a genuinely paid (active) subscription — a trialing store
        // must be able to convert to paid.
        $existing = $this->subscription($request);
        if ($existing && $existing->status === 'active') {
            return back()->with('error', 'You already have an active subscription. Manage it from billing.');
        }

        $tenant = $request->user()->tenant;

        // BIZ-04 — a store re-subscribing while `past_due` (or carrying a stale
        // pending reference) would leave the PREVIOUS Razorpay subscription live and
        // get billed twice. Cancel it first; best-effort so a Razorpay hiccup can't
        // block the store from paying.
        if ($existing?->razorpay_subscription_id
            && $existing->status !== 'canceled'
            && $billing->isConfigured()) {
            try {
                $billing->cancelSubscription($existing->razorpay_subscription_id);
            } catch (\Throwable $e) {
                Log::warning('Could not cancel the previous Razorpay subscription before re-subscribing.', [
                    'tenant_id' => $tenant->id,
                    'razorpay_subscription_id' => $existing->razorpay_subscription_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $result = $billing->createSubscription($tenant, $validated['tier'], $validated['interval']);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not start checkout: ' . $e->getMessage());
        }

        // Persist the pending subscription reference (keeps trial state until the
        // webhook activates it).
        Subscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'razorpay_subscription_id' => $result['subscription_id'],
                'tier' => $validated['tier'],
                'interval' => $validated['interval'],
                'pending_interval' => null,
                'status' => 'trialing',
                'cancel_at_period_end' => false,
            ],
        );

        return view('tenant.billing.checkout', [
            'subscriptionId' => $result['subscription_id'],
            'razorpayKey' => $billing->publicKey(),
            'tier' => $validated['tier'],
            'interval' => $validated['interval'],
            'user' => $request->user(),
        ]);
    }

    /** Switch billing cycle (monthly <-> yearly), effective at the next renewal. */
    public function changeInterval(Request $request, BillingService $billing): RedirectResponse
    {
        if ($blocked = $this->supervisionBlock($request)) {
            return back()->with('error', $blocked);
        }

        $validated = $request->validate(['interval' => ['required', 'in:monthly,yearly']]);

        $subscription = $this->subscription($request);

        if (! $subscription || $subscription->status !== 'active') {
            return back()->with('error', 'You can change your billing cycle once your subscription is active.');
        }

        if ($validated['interval'] === $subscription->interval) {
            return back()->with('error', "You're already on the {$validated['interval']} billing cycle.");
        }

        if ($billing->isConfigured() && $subscription->razorpay_subscription_id) {
            try {
                $billing->changeInterval(
                    $subscription->razorpay_subscription_id,
                    $subscription->tier ?? 'basic',
                    $validated['interval'],
                );
            } catch (\Throwable $e) {
                // Razorpay/RBI e-mandate rule: a card-authorized subscription's plan_id
                // (and thus its billing interval) can't be changed in place — only
                // offer_id can. Card customers must cancel and resubscribe on the new
                // plan; UPI Autopay isn't affected by this restriction.
                if (str_contains($e->getMessage(), 'Only offers can be updated')) {
                    return back()->with('error', "Billing-cycle switching isn't supported for card payments. Please cancel your subscription and resubscribe on the {$validated['interval']} plan, or use UPI Autopay instead.");
                }

                return back()->with('error', 'Could not change your billing cycle: ' . $e->getMessage());
            }
        }

        $subscription->update(['pending_interval' => $validated['interval']]);

        return back()->with('status', "Your billing cycle will switch to {$validated['interval']} at your next renewal.");
    }

    /** Cancel at the end of the current billing period (access continues until then). */
    public function cancel(Request $request, BillingService $billing): RedirectResponse
    {
        if ($blocked = $this->supervisionBlock($request)) {
            return back()->with('error', $blocked);
        }

        $subscription = $this->subscription($request);

        if (! $subscription || $subscription->status !== 'active') {
            return back()->with('error', 'There is no active subscription to cancel.');
        }

        if ($billing->isConfigured() && $subscription->razorpay_subscription_id) {
            try {
                $billing->cancelSubscription($subscription->razorpay_subscription_id);
            } catch (\Throwable $e) {
                return back()->with('error', 'Could not cancel your subscription: ' . $e->getMessage());
            }
        }

        $subscription->update(['cancel_at_period_end' => true]);

        return back()->with('status', 'Your subscription will cancel at the end of the current billing period.');
    }

    /** Razorpay redirects/callbacks here after a successful checkout. */
    public function success(Request $request): RedirectResponse
    {
        // The authoritative state change happens via the webhook; this just
        // gives the user friendly feedback.
        return redirect()->route('tenant.billing.index')
            ->with('status', 'Payment received — your subscription will activate momentarily.');
    }

    /**
     * Download the document for a subscription payment (tenant-scoped binding).
     *
     * BUG-P02 — this is a tax invoice only when the platform is GST-registered;
     * otherwise it is a plain payment receipt. The filename follows, so a customer
     * filing it away is not told it is an invoice when it is not.
     */
    public function invoicePdf(Request $request, SubscriptionInvoice $invoice): Response
    {
        $tenant = $request->user()->tenant;

        $pdf = Pdf::loadView('tenant.billing.invoice-pdf', [
            'invoice' => $invoice,
            'tenant' => $tenant,
        ]);

        $date = optional($invoice->paid_at ?? $invoice->created_at)->format('Y-m-d');
        $kind = config('saas.gst_registered') ? 'invoice' : 'receipt';

        return $pdf->download("OSMS-{$kind}-{$date}.pdf");
    }
}
