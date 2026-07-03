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
use Illuminate\View\View;

class BillingController extends Controller
{
    public function index(Request $request, BillingService $billing): View
    {
        $subscription = Subscription::first();

        // Single-plan launch: only the Basic plan is offered.
        $plans = ['basic' => config('billing.plans.basic')];

        $invoices = SubscriptionInvoice::latest('paid_at')->get();

        return view('tenant.billing.index', [
            'subscription' => $subscription,
            'plans' => $plans,
            'invoices' => $invoices,
            'configured' => $billing->isConfigured(),
        ]);
    }

    public function subscribe(Request $request, BillingService $billing): RedirectResponse|View
    {
        $validated = $request->validate([
            'tier' => ['required', 'in:basic'],
            'interval' => ['required', 'in:monthly,yearly'],
        ]);

        if (! $billing->isConfigured()) {
            return back()->with('error', 'Online payments are not configured yet. Please contact support.');
        }

        // Block only a genuinely paid (active) subscription — a trialing store
        // must be able to convert to paid.
        $existing = Subscription::first();
        if ($existing && $existing->status === 'active') {
            return back()->with('error', 'You already have an active subscription. Manage it from billing.');
        }

        $tenant = $request->user()->tenant;

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
        $validated = $request->validate(['interval' => ['required', 'in:monthly,yearly']]);

        $subscription = Subscription::first();

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
                return back()->with('error', 'Could not change your billing cycle: ' . $e->getMessage());
            }
        }

        $subscription->update(['pending_interval' => $validated['interval']]);

        return back()->with('status', "Your billing cycle will switch to {$validated['interval']} at your next renewal.");
    }

    /** Cancel at the end of the current billing period (access continues until then). */
    public function cancel(Request $request, BillingService $billing): RedirectResponse
    {
        $subscription = Subscription::first();

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

    /** Download a GST invoice for a subscription payment (tenant-scoped binding). */
    public function invoicePdf(Request $request, SubscriptionInvoice $invoice): Response
    {
        $tenant = $request->user()->tenant;

        $pdf = Pdf::loadView('tenant.billing.invoice-pdf', [
            'invoice' => $invoice,
            'tenant' => $tenant,
        ]);

        $date = optional($invoice->paid_at ?? $invoice->created_at)->format('Y-m-d');

        return $pdf->download("OSMS-invoice-{$date}.pdf");
    }
}
