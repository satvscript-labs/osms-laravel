<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Services\BillingEngine;
use App\Services\BillingService;
use App\Services\Settlement;
use App\Services\SubscriptionLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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

        // E2 — what the PAYER has been asked for (account-owned, E2-H): read through
        // the account, never the tenant scope. Paying it online arrives with E3.
        $account = $request->user()?->tenant?->account;
        $owed = $account
            ? $account->charges()->pending()->with('tenant:id,store_name')->orderBy('created_at')->get()
            : collect();

        return view('tenant.billing.index', [
            'subscription' => $subscription,
            'owed' => $owed,
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

    /** FB-11 — what a customer is told when the gateway fails. Details go to the log only. */
    private const GATEWAY_TROUBLE = 'We could not reach the payment service just now. Nothing was charged — please try again in a few minutes.';

    /**
     * E3-I + D-3 + FB-11 — may THIS person pay online right now? Null = yes; otherwise
     * the sentence that says why not. Enforced server-side for every pay door.
     */
    private function cannotPayOnline(Request $request, BillingService $billing): ?string
    {
        if ($blocked = $this->supervisionBlock($request)) {
            return $blocked;
        }

        if (! $billing->isConfigured()) {
            return "Online payment isn't available right now. To pay, contact us at " . config('saas.support_email') . '.';
        }

        $account = $request->user()?->tenant?->account;

        if (! $account) {
            return 'We could not find your account. Please contact us and we will sort it out.';
        }

        // FB-05 — money for the account is the payer's decision, not any branch admin's.
        if ((int) $account->owner_user_id !== (int) $request->user()->id) {
            return 'Only the account owner' . ($account->owner?->name ? " ({$account->owner->name})" : '')
                . ' can pay online. Ask them, or contact us.';
        }

        return null;
    }

    /**
     * E3 — pay for the next term (their first, after a trial) with a Razorpay
     * ORDER for the engine's price. Nothing about access changes until the money
     * is confirmed (rule 2). The route keeps its old name; the lane behind it is
     * new (D-1 = B: no Razorpay plans or subscriptions).
     */
    public function subscribe(Request $request, BillingService $billing): RedirectResponse|View
    {
        if ($why = $this->cannotPayOnline($request, $billing)) {
            return back()->with('error', $why);
        }

        $validated = $request->validate(['interval' => ['required', 'in:monthly,yearly']]);
        $subscription = $this->subscription($request);

        if (! $subscription) {
            return back()->with('error', 'We could not find your subscription. Please contact us and we will sort it out.');
        }

        try {
            $bill = app(BillingEngine::class)->raiseTerm($subscription, [
                'interval' => $validated['interval'],
                'via' => 'owner',
                'by' => $request->user()->id,
            ]);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());   // the engine's sentences are written for people
        }

        return $this->openCheckout($request, $billing, $bill);
    }

    /** E3 — pay one open bill (a new branch's, a reopened one) online. */
    public function pay(Request $request, BillingService $billing): RedirectResponse|View
    {
        if ($why = $this->cannotPayOnline($request, $billing)) {
            return back()->with('error', $why);
        }

        $validated = $request->validate(['charge' => ['required', 'string', 'max:64']]);

        // Through the payer's account only: another account's bill never resolves (404).
        $bill = $request->user()->tenant->account->charges()->whereKey($validated['charge'])->first();
        abort_unless($bill, 404);

        return $this->openCheckout($request, $billing, $bill);
    }

    private function openCheckout(Request $request, BillingService $billing, BillingCharge $bill): RedirectResponse|View
    {
        try {
            $bill = app(BillingEngine::class)->openCheckout($bill, fn (BillingCharge $locked) => $billing->createOrder($locked));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('Could not open online checkout.', ['charge' => $bill->number, 'error' => $e->getMessage()]);

            return back()->with('error', self::GATEWAY_TROUBLE);
        }

        return view('tenant.billing.checkout', [
            'bill' => $bill,
            'orderId' => $bill->gateway_order_id,
            'razorpayKey' => $billing->publicKey(),
            'user' => $request->user(),
        ]);
    }

    /**
     * E3 — Razorpay Checkout's callback. Only Razorpay's three ids are accepted from
     * the browser; the signature is checked and the payment is fetched from Razorpay,
     * so the amount and status are Razorpay's, never the browser's. Settlement
     * makes it idempotent with the webhook (whichever lands first).
     */
    public function paid(Request $request, BillingService $billing, Settlement $settlement): RedirectResponse
    {
        $validated = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:64'],
            'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:128'],
        ]);

        $orderId = $validated['razorpay_order_id'];
        $paymentId = $validated['razorpay_payment_id'];
        $notConfirmed = 'We could not confirm that payment here. If money left your account, it will still reach us and show on this page within a few minutes — or contact us.';

        if (! $billing->verifyPaymentSignature($orderId, $paymentId, $validated['razorpay_signature'])) {
            Log::warning('Checkout callback with a bad signature.', ['order' => $orderId, 'payment' => $paymentId]);

            return redirect()->route('tenant.billing.index')->with('error', $notConfirmed);
        }

        $account = $request->user()?->tenant?->account;
        $bill = BillingCharge::query()->where('gateway_order_id', $orderId)->first();

        if (! $account || ! $bill || $bill->account_id !== $account->id) {
            return redirect()->route('tenant.billing.index')->with('error', $notConfirmed);
        }

        try {
            $payment = $billing->fetchPayment($paymentId);
        } catch (\Throwable $e) {
            Log::warning('Could not fetch the payment after checkout; the webhook will confirm it.', ['payment' => $paymentId, 'error' => $e->getMessage()]);

            return redirect()->route('tenant.billing.index')->with('status', 'Thank you — we are confirming your payment with the bank. It will show here within a few minutes.');
        }

        if (($payment['order_id'] ?? null) !== $orderId) {
            Log::warning('Checkout callback: the payment belongs to another order.', ['order' => $orderId, 'payment' => $paymentId]);

            return redirect()->route('tenant.billing.index')->with('error', $notConfirmed);
        }

        $result = $settlement->settleGateway($payment, 'callback');

        return redirect()->route('tenant.billing.index')->with(...match ($result['outcome']) {
            'settled', 'already' => ['status', 'Payment received — thank you. Receipt ' . $result['row']->receipt_no . ' is in your payment history.'],
            'not_captured' => ['status', 'Thank you — we are confirming your payment with the bank. It will show here within a few minutes.'],
            default => ['error', 'We received your payment of ₹ ' . number_format(((int) $payment['amount']) / 100, 2)
                . ', but it did not match an open bill. We have been told and will sort it out — you do not need to pay again.'],
        });
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

        // E3-E — no Razorpay plan to switch any more: the next bill is priced at the
        // new period by the engine when it is raised.

        // E1.3 (W8) — through the engine, audited as the owner.
        app(SubscriptionLifecycle::class)->ownerRequestInterval($subscription, $validated['interval']);

        // Choosing the period they are on undid a pending switch — say that, not "will switch".
        if ($validated['interval'] === $subscription->interval) {
            return back()->with('status', "You'll stay on {$validated['interval']} billing. The switch is cancelled.");
        }

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

        // E1.3 (W8) — through the engine, audited as the owner. Any legacy autopay is
        // stopped by the engine at the cycle end (E1.10) — a gateway hiccup can no
        // longer block a cancellation, nor be shown to the customer raw (FB-11).
        app(SubscriptionLifecycle::class)->ownerCancelAtPeriodEnd($subscription);

        return back()->with('status', 'Your subscription will cancel at the end of the current billing period.');
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
