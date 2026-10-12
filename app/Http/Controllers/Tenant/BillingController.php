<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\BillingService;
use App\Services\SubscriptionLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * QA-08 — the receipts of the PAYER, not of this one store.
     *
     * Money lives on the account (REQ-12): one subscription, one ledger. The
     * history used to be read through the tenant scope, so a second branch never
     * saw the payments recorded against the first, and once a store was purged its
     * rows — kept on purpose (FB-22), now tagged to no store — vanished from every
     * remaining branch: "No payments yet", and no way to download a receipt the
     * customer had paid for. Same payer, same ledger, wherever they sign in.
     *
     * Pre-backfill (no account yet) it is the store's own rows, exactly as before.
     */
    private function ledger(Request $request): Builder|HasMany
    {
        $tenant = $request->user()?->tenant;

        if (! $tenant) {
            return SubscriptionInvoice::query()->whereRaw('1 = 0');
        }

        return $tenant->account
            ? $tenant->account->invoices()
            : SubscriptionInvoice::query();
    }

    public function index(Request $request, BillingService $billing): View
    {
        $subscription = $this->subscription($request);

        // Single-plan launch: only the Basic plan is offered.
        $plans = ['basic' => config('billing.plans.basic')];

        $invoices = $this->ledger($request)->with('tenant')->newestFirst()->get();

        return view('tenant.billing.index', [
            'subscription' => $subscription,
            'supervised' => $this->supervisionBlock($request),
            'plans' => $plans,
            'invoices' => $invoices,
            'configured' => $billing->isConfigured(),
            /*
             * ISS-02 (decision N-b) — tell them, once, when money they were
             * charged has been taken back.
             *
             * Only RECENT reversals: this is a notice, not a permanent scar on
             * the page. The row itself stays marked forever in the history
             * below, which is where an old reversal belongs.
             *
             * Deliberately not an email. Most reversals are our own keying
             * error, and mailing a customer about our typo alarms more than it
             * informs — but anyone who opens this page should not have to spot
             * it for themselves.
             */
            'recentReversals' => $invoices
                ->filter(fn ($i) => $i->isReversed() && $i->reversed_at->gt(now()->subDays(30)))
                ->sortByDesc('reversed_at')
                ->values(),
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

        // E1.3 (W7) — every account has its one subscription from birth. If it is
        // somehow missing, refuse BEFORE opening anything at the gateway; never
        // mint a second clock here (L-3).
        if (! $existing) {
            return back()->with('error', 'We could not find your subscription. Please contact us and we will sort it out.');
        }

        if ($existing->status === 'active') {
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

        // E1.3 (W7) — link the gateway reference to the ACCOUNT's one subscription.
        // Rule 2: nothing about access changes until the webhook confirms the money
        // (this used to set status = trialing before any payment, and was keyed on
        // tenant_id, so a second branch minted a second clock — L-3, L-4).
        app(SubscriptionLifecycle::class)->linkCheckout(
            $existing,
            $result['subscription_id'],
            $validated['interval'],
            $validated['tier'],
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

        // Choosing the period they are on is an error only when nothing is pending;
        // with a switch pending it CANCELS the switch (the operator can set one too,
        // and the owner must be able to undo it).
        if ($validated['interval'] === $subscription->interval && ! $subscription->pending_interval) {
            return back()->with('error', "You're already on the {$validated['interval']} billing cycle.");
        }

        if ($validated['interval'] === $subscription->pending_interval) {
            return back()->with('error', "You're already switching to {$validated['interval']} at your next renewal.");
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

        // E1.3 (W8) — through the engine, audited as the owner.
        app(SubscriptionLifecycle::class)->ownerRequestInterval($subscription, $validated['interval']);

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

        // E1.3 (W8) — through the engine, audited as the owner.
        app(SubscriptionLifecycle::class)->ownerCancelAtPeriodEnd($subscription);

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
    public function invoicePdf(Request $request, string $invoice): Response
    {
        // QA-08 — resolved through the payer's ledger, so a branch can download
        // any receipt of its own account and nothing of anyone else's (404).
        $invoice = $this->ledger($request)->whereKey($invoice)->firstOrFail();

        $pdf = Pdf::loadView('tenant.billing.invoice-pdf', [
            'invoice' => $invoice,
            // Received from: the store the payment was recorded against; if that
            // store has since been purged, the branch asking for it (same payer).
            'tenant' => $invoice->tenant ?? $request->user()->tenant,
        ]);

        $date = optional($invoice->paid_at ?? $invoice->created_at)->format('Y-m-d');
        $kind = config('saas.gst_registered') ? 'invoice' : 'receipt';

        // ISS-02 — the filename says it too. A folder of receipts is scanned by
        // name, not opened one by one, so a cancelled one must be identifiable
        // without being read.
        $suffix = $invoice->isReversed() ? '-REVERSED' : '';

        return $pdf->download("OSMS-{$kind}-{$date}{$suffix}.pdf");
    }
}
