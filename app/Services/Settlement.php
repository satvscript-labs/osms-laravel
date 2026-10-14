<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\UnappliedPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * E2.3 — what was RECEIVED, against what was OWED (06 §4).
 *
 * The one path that turns a charge into money on the ledger and, where the charge
 * buys time, into coverage — the coverage itself written by SubscriptionLifecycle,
 * the only writer of access (rule 1). Every payment therefore says what it paid for
 * (rule 6), and a payment can never buy something twice (E2-G).
 *
 * One transaction per call; the subscription row is locked first, then the charge
 * (the engine-wide lock order), so a settle, a raise and an operator action for
 * the same customer are serialised.
 */
class Settlement
{
    /** The old one-step money actions, now "raise the next term, then settle it". */
    public const TERM_ACTIONS = ['renew', 'mark_paid', 'waive'];

    /** Methods an operator may record by hand. */
    public const HAND_METHODS = ['cash', 'upi', 'bank_transfer', 'cheque'];

    public function __construct(
        private readonly BillingEngine $engine,
        private readonly PaymentRecorder $payments,
        private readonly SubscriptionLifecycle $lifecycle,
    ) {}

    // ---------------------------------------------------------------
    // Settle — money received for one charge
    // ---------------------------------------------------------------

    /**
     * @param array{amount?: float|string|null, method?: string, reference?: ?string, reason?: ?string,
     *              paid_at?: Carbon|string|null, discount_reason?: ?string, confirm_over?: mixed,
     *              source?: string, action?: string} $payment
     */
    public function settle(BillingCharge $charge, array $payment): SubscriptionInvoice
    {
        return MoneyWrite::transaction(function () use ($charge, $payment) {
            [$sub, $row] = $this->lockForSettle($charge);
            $this->assertNotBeingPaidOnline($row, $payment);

            $amount = $this->amountFor($row, $payment);
            $method = (string) ($payment['method'] ?? 'cash');

            // A-6 — money an operator records by hand is cash, UPI, a transfer or a
            // cheque. "adjustment" would move the clock yet be left out of collected
            // revenue; "razorpay" would be gateway money nobody's gateway saw. Only
            // the gateway lane (E3) may record those, and it says so via `source`.
            if (($payment['source'] ?? 'operator') === 'operator' && ! in_array($method, self::HAND_METHODS, true)) {
                throw new InvalidArgumentException('Record cash, UPI, a bank transfer or a cheque here. Online payments are recorded by the gateway itself.');
            }

            if ($amount <= 0.0) {
                throw new InvalidArgumentException(($payment['action'] ?? null) === 'mark_paid'
                    ? 'Marking a payment as received needs an amount. To give them the cycle for free, use Waive instead.'
                    : 'A payment needs an amount. To give it for free, use Waive instead — it records a zero-value entry with your reason.');
            }

            $this->assertNotOverWithoutConfirm($row, $amount, $payment);
            $paidAt = $this->paidAt($payment['paid_at'] ?? null);

            [$grantStart, $grantEnd] = $this->grant($sub, $row, $payment['action'] ?? 'renew',
                trim((string) ($payment['reason'] ?? '')), free: false, input: $payment);

            $invoice = $this->payments->record($sub, $amount, $method, [
                'charge_id' => $row->id,
                'tenant_id' => $row->tenant_id ?? $sub->tenant_id,
                'reference' => $payment['reference'] ?? null,
                'reason' => $payment['reason'] ?? null,
                'paid_at' => $paidAt,
                'source' => $payment['source'] ?? 'operator',
                'razorpay_payment_id' => $payment['gateway_payment_id'] ?? null,
                'period_start' => $grantStart ?? $row->granted_start ?? $row->period_start,
                'period_end' => $grantEnd ?? $row->granted_end ?? $row->period_end,
                'granted_start' => $grantStart,
                'granted_end' => $grantEnd,
                // Only a part-period charge carries its working onto the receipt (as
                // before); a term's working lives on the charge.
                'calculation' => $row->kind === 'branch_join' ? $row->calculation : null,
            ] + $this->bargain((float) $row->amount, $amount, $payment));

            $this->markPaid($row, $grantStart, $grantEnd);
            $charge->setRawAttributes($row->getAttributes(), true);

            AdminAuditLog::record('payment.recorded',
                'Recorded ₹' . number_format($amount, 2) . " ({$invoice->methodLabel()}) for "
                    . ($sub->account?->displayName() ?? 'account') . " — pays {$row->number}",
                $invoice->tenant_id, array_filter([
                    'account_id' => $row->account_id,
                    'receipt_no' => $invoice->receipt_no,
                    'charge' => $row->number,
                    'amount' => $amount,
                    'method' => $method,
                    'over_the_bill' => $amount > (float) $row->amount + 0.005 ? round($amount - (float) $row->amount, 2) : null,
                    'no_new_coverage' => $grantEnd === null && $row->kind !== 'branch_join' ? 'already granted before a reversal' : null,
                    'gateway_payment_id' => $payment['gateway_payment_id'] ?? null,
                    'actor' => $payment['actor'] ?? null,   // "gateway" when Razorpay confirmed it
                ], fn ($v) => $v !== null));

            return $invoice;
        });
    }

    /**
     * ₹0: the charge is given away, visibly (a comp row with the reason), and
     * still buys what it was for — a waived term grants its time (as Waive always did).
     */
    public function waive(BillingCharge $charge, string $reason, array $input = []): SubscriptionInvoice
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to waive — it is a discretionary decision and the audit trail must record why.');
        }

        return MoneyWrite::transaction(function () use ($charge, $reason, $input) {
            [$sub, $row] = $this->lockForSettle($charge);
            $this->assertNotBeingPaidOnline($row, $input);

            [$grantStart, $grantEnd] = $this->grant($sub, $row, $input['action'] ?? 'waive', $reason, free: true, input: $input);

            $invoice = $this->payments->record($sub, 0.0, 'comp', [
                'charge_id' => $row->id,
                'tenant_id' => $row->tenant_id ?? $sub->tenant_id,
                'reason' => "Waived: {$reason}",
                'period_start' => $grantStart ?? $row->granted_start ?? $row->period_start,
                'period_end' => $grantEnd ?? $row->granted_end ?? $row->period_end,
                'granted_start' => $grantStart,
                'granted_end' => $grantEnd,
                // Only a part-period charge carries its working onto the receipt (as
                // before); a term's working lives on the charge.
                'calculation' => $row->kind === 'branch_join' ? $row->calculation : null,
            ]);

            $this->markPaid($row, $grantStart, $grantEnd);
            $charge->setRawAttributes($row->getAttributes(), true);

            AdminAuditLog::record('charge.waived', "Waived {$row->number} (₹" . number_format((float) $row->amount, 2) . "): {$reason}",
                $invoice->tenant_id, ['account_id' => $row->account_id, 'charge' => $row->number, 'receipt_no' => $invoice->receipt_no]);

            return $invoice;
        });
    }

    // ---------------------------------------------------------------
    // The next term in one step (Record a payment → next renewal; the old
    // Renew now / Mark paid / Waive levers)
    // ---------------------------------------------------------------

    public function payTerm(Subscription $subscription, string $action, array $input = []): SubscriptionInvoice
    {
        if (! in_array($action, self::TERM_ACTIONS, true)) {
            throw new InvalidArgumentException("Unknown payment action [{$action}].");
        }

        $reason = trim((string) ($input['reason'] ?? ''));

        if (in_array($action, ['mark_paid', 'waive'], true) && $reason === '') {
            throw new InvalidArgumentException("A reason is required to {$action} — it is a discretionary decision and the audit trail must record why.");
        }

        return MoneyWrite::transaction(function () use ($subscription, $action, $input, $reason) {
            // A-1 — two operators (or two tabs) renewing the same customer: the
            // once-per-submission token stops the SAME form twice, not two forms.
            // The form says which renewal it was opened for; if the clock has moved
            // since, this is a second renewal nobody asked for.
            if (array_key_exists('renews_from', $input) && $input['renews_from'] !== null && $input['renews_from'] !== '') {
                $now = Subscription::withoutGlobalScopes()->whereKey($subscription->getKey())->lockForUpdate()->value('current_period_end');
                $now = $now ? Carbon::parse($now)->toDateString() : 'none';

                if ($now !== (string) $input['renews_from']) {
                    throw new InvalidArgumentException('Their renewal changed since this page was opened (covered until '
                        . ($now === 'none' ? 'no date' : Carbon::parse($now)->format('d M Y'))
                        . '). Reload to see it before taking another payment — nothing was recorded.');
                }
            }

            $charge = $this->engine->raiseTerm($subscription, [
                'interval' => ($input['interval'] ?? null) ?: null,
                'cycles' => (int) (($input['cycles'] ?? null) ?: 1),
                'via' => $input['via'] ?? 'operator',
            ]);

            return $action === 'waive'
                ? $this->waive($charge, $reason, ['action' => 'waive'] + $input)
                : $this->settle($charge, ['action' => $action] + $input);
        });
    }

    // ---------------------------------------------------------------
    // E3 — the gateway door: Razorpay says money arrived
    // ---------------------------------------------------------------

    /**
     * Settle the bill a Razorpay payment was for — or, if it can't buy that bill,
     * keep the money as UNAPPLIED and say so (rule 4: never a silent drop).
     *
     * Called by the checkout callback AND the webhook, in either order and possibly
     * at the same moment: idempotent on the payment id, checked under the lock
     * (and backed by the ledger's unique key).
     *
     * @param  array{id: string, order_id: ?string, amount: int, currency: string, status: ?string, created_at: ?int}  $payment  as Razorpay recorded it (amount in paise)
     * @return array{outcome: string, row: ?SubscriptionInvoice, unapplied: ?UnappliedPayment}
     *         outcome: settled · already · unapplied · not_captured
     */
    public function settleGateway(array $payment, string $via, ?string $event = null): array
    {
        $paymentId = (string) ($payment['id'] ?? '');
        $orderId = $payment['order_id'] ?? null;
        $rupees = round(((int) ($payment['amount'] ?? 0)) / 100, 2);

        if ($paymentId === '') {
            throw new InvalidArgumentException('A gateway payment without an id cannot be recorded.');
        }

        if (($payment['status'] ?? null) !== 'captured') {
            // Authorized-but-not-captured (or failed): nothing is ours yet. With
            // automatic capture the webhook brings the captured payment shortly.
            return ['outcome' => 'not_captured', 'row' => null, 'unapplied' => null];
        }

        $charge = $orderId ? BillingCharge::query()->where('gateway_order_id', $orderId)->first() : null;

        if (! $charge) {
            return $this->unappliedOutcome($payment, null, null, $orderId
                ? "Paid online for order {$orderId}, which OSMS has no bill for."
                : 'Paid online with no order attached.', $event);
        }

        return MoneyWrite::transaction(function () use ($payment, $paymentId, $rupees, $charge, $via, $event) {
            $sub = $this->lockSubscriptionOf($charge->account_id);
            $row = BillingCharge::query()->whereKey($charge->getKey())->lockForUpdate()->firstOrFail();

            $already = SubscriptionInvoice::withoutGlobalScopes()->where('razorpay_payment_id', $paymentId)->first();

            if ($already) {
                return ['outcome' => 'already', 'row' => $already, 'unapplied' => null];
            }

            if ($open = UnappliedPayment::query()->where('gateway_payment_id', $paymentId)->first()) {
                return ['outcome' => 'unapplied', 'row' => null, 'unapplied' => $open];
            }

            if (! $sub || ! $row->isPending()) {
                return $this->unappliedOutcome($payment, $row, $row->account_id,
                    "Paid online for {$row->number}, which is " . mb_strtolower($row->statusLabel())
                    . ' — refund it, or apply it to another bill.', $event);
            }

            // A-14 — bills are in rupees; anything else is not compared as if it were.
            if (strtoupper((string) ($payment['currency'] ?? 'INR')) !== 'INR') {
                return $this->unappliedOutcome($payment, $row, $row->account_id,
                    "Paid in {$payment['currency']} for {$row->number} — bills are in rupees. Refund it, or apply it by hand.", $event);
            }

            if (abs($rupees - (float) $row->amount) > 0.005) {
                return $this->unappliedOutcome($payment, $row, $row->account_id,
                    'Paid ₹ ' . number_format($rupees, 2) . " online for {$row->number}, but the bill is ₹ "
                    . number_format((float) $row->amount, 2) . ' — the amounts differ.', $event);
            }

            try {
                $ledger = $this->settle($row, [
                    'amount' => $rupees,
                    'method' => 'razorpay',
                    'source' => 'gateway',
                    'actor' => 'gateway',
                    'reference' => $row->gateway_order_id,
                    'gateway_payment_id' => $paymentId,
                    'paid_at' => isset($payment['created_at']) ? Carbon::createFromTimestamp((int) $payment['created_at']) : null,
                    'reason' => 'Paid online (' . $via . ')',
                ]);
            } catch (InvalidArgumentException $e) {
                // A-13 — a business refusal must never become a webhook retry loop
                // that ends with the money recorded nowhere. Keep it, say why.
                return $this->unappliedOutcome($payment, $row, $row->account_id,
                    "Paid online for {$row->number}, but it could not be applied: " . $e->getMessage(), $event);
            }

            return ['outcome' => 'settled', 'row' => $ledger, 'unapplied' => null];
        });
    }

    /**
     * Keep gateway money that bought nothing — once per payment — and alert.
     * Not collected revenue until a person applies it (06 §7).
     */
    public function recordUnapplied(array $payment, ?BillingCharge $charge, ?string $accountId, string $reason, ?string $event = null): UnappliedPayment
    {
        return MoneyWrite::transaction(fn () => MoneyWrite::during(function () use ($payment, $charge, $accountId, $reason, $event) {
            $existing = UnappliedPayment::query()->where('gateway_payment_id', (string) $payment['id'])->lockForUpdate()->first();

            if ($existing) {
                return $existing;
            }

            $row = UnappliedPayment::create([
                'account_id' => $accountId,
                'charge_id' => $charge?->id,
                'gateway' => 'razorpay',
                'gateway_payment_id' => (string) $payment['id'],
                'gateway_order_id' => $payment['order_id'] ?? null,
                'gateway_event' => $event,
                'amount' => round(((int) ($payment['amount'] ?? 0)) / 100, 2),
                'currency' => (string) ($payment['currency'] ?? 'INR'),
                'reason' => mb_substr($reason, 0, 500),
                'status' => 'open',
                'paid_at' => isset($payment['created_at']) ? Carbon::createFromTimestamp((int) $payment['created_at']) : now(),
            ]);

            AdminAuditLog::record('payment.unapplied', '₹' . number_format((float) $row->amount, 2)
                . " received online and not applied: {$reason}", $charge?->tenant_id, array_filter([
                    'account_id' => $accountId,
                    'gateway_payment_id' => $row->gateway_payment_id,
                    'gateway_order_id' => $row->gateway_order_id,
                    'charge' => $charge?->number,
                    'actor' => 'gateway',
                ], fn ($v) => $v !== null));

            return $row;
        }));
    }

    /**
     * The operator puts unapplied money on the customer's NEXT RENEWAL when no open
     * bill can take it — the usual case for an unknown order or a legacy autopay
     * charge. The renewal bill is raised and settled in one transaction: if the
     * amount differs, nothing is raised and the money stays unapplied.
     */
    public function applyUnappliedToRenewal(UnappliedPayment $unapplied, Subscription $subscription, string $note): SubscriptionInvoice
    {
        if (trim($note) === '') {
            throw new InvalidArgumentException('Say why this money belongs to this bill — the record has to explain it later.');
        }

        return MoneyWrite::transaction(function () use ($unapplied, $subscription, $note) {
            // The money row first, then the subscription — the same order applyUnapplied() takes (A-10).
            UnappliedPayment::query()->whereKey($unapplied->getKey())->lockForUpdate()->firstOrFail();

            $charge = $this->engine->raiseTerm($subscription, ['via' => 'operator']);

            return $this->applyUnapplied($unapplied, $charge, $note);
        });
    }

    /**
     * The operator puts unapplied money on a bill: it settles that bill exactly as
     * if it had arrived for it (once). The amount must equal the bill (E3-C).
     */
    public function applyUnapplied(UnappliedPayment $unapplied, BillingCharge $charge, string $note): SubscriptionInvoice
    {
        $note = trim($note);

        if ($note === '') {
            throw new InvalidArgumentException('Say why this money belongs to this bill — the record has to explain it later.');
        }

        return MoneyWrite::transaction(function () use ($unapplied, $charge, $note) {
            $u = UnappliedPayment::query()->whereKey($unapplied->getKey())->lockForUpdate()->firstOrFail();

            if (! $u->isOpen()) {
                throw new InvalidArgumentException('That payment has already been ' . $u->status . '.');
            }

            if ($u->account_id && $u->account_id !== $charge->account_id) {
                throw new InvalidArgumentException('That payment came from a different customer. Apply it to one of their bills.');
            }

            if (abs((float) $u->amount - (float) $charge->amount) > 0.005) {
                throw new InvalidArgumentException('₹ ' . number_format((float) $u->amount, 2) . ' does not match the bill of ₹ '
                    . number_format((float) $charge->amount, 2) . '. Apply it to a bill of the same amount, or refund it.');
            }

            $ledger = $this->settle($charge, [
                'amount' => (float) $u->amount,
                'method' => 'razorpay',
                'source' => 'unapplied',
                'reference' => $u->gateway_order_id,
                'gateway_payment_id' => $u->gateway_payment_id,
                'paid_at' => $u->paid_at,
                'reason' => "Applied by hand: {$note}",
            ]);

            MoneyWrite::during(fn () => $u->forceFill([
                'status' => 'applied',
                'account_id' => $charge->account_id,
                'charge_id' => $charge->id,
                'ledger_row_id' => $ledger->id,
                'resolved_by' => auth()->id(),
                'resolved_at' => now(),
                'resolution_note' => $note,
            ])->save());

            AdminAuditLog::record('payment.unapplied_applied', '₹' . number_format((float) $u->amount, 2)
                . " received online applied to {$charge->number}: {$note}", $charge->tenant_id,
                ['account_id' => $charge->account_id, 'charge' => $charge->number, 'gateway_payment_id' => $u->gateway_payment_id, 'receipt_no' => $ledger->receipt_no]);

            $unapplied->setRawAttributes($u->getAttributes(), true);

            return $ledger;
        });
    }

    /**
     * Money from an order OSMS didn't know has no customer yet: the operator says
     * whose it is (with why), so it can be applied on that customer's page.
     */
    public function assignUnapplied(UnappliedPayment $unapplied, string $accountId, string $note): UnappliedPayment
    {
        $note = trim($note);

        if ($note === '') {
            throw new InvalidArgumentException('Say how you know whose payment this is — the record has to explain it later.');
        }

        return MoneyWrite::transaction(fn () => MoneyWrite::during(function () use ($unapplied, $accountId, $note) {
            $u = UnappliedPayment::query()->whereKey($unapplied->getKey())->lockForUpdate()->firstOrFail();

            if (! $u->isOpen()) {
                throw new InvalidArgumentException('That payment has already been ' . $u->status . '.');
            }

            if ($u->account_id) {
                throw new InvalidArgumentException('That payment is already matched to a customer.');
            }

            $u->forceFill(['account_id' => $accountId, 'resolution_note' => $note])->save();

            AdminAuditLog::record('payment.unapplied_assigned', '₹' . number_format((float) $u->amount, 2)
                . " received online matched to a customer: {$note}", null,
                ['account_id' => $accountId, 'gateway_payment_id' => $u->gateway_payment_id]);

            $unapplied->setRawAttributes($u->getAttributes(), true);

            return $u;
        }));
    }

    /** E3-D — refunded in the Razorpay dashboard; OSMS records that it was. */
    public function markRefunded(UnappliedPayment $unapplied, string $note): UnappliedPayment
    {
        $note = trim($note);

        if ($note === '') {
            throw new InvalidArgumentException('Note the refund (e.g. the Razorpay refund id) — the record has to explain it later.');
        }

        return MoneyWrite::transaction(fn () => MoneyWrite::during(function () use ($unapplied, $note) {
            $u = UnappliedPayment::query()->whereKey($unapplied->getKey())->lockForUpdate()->firstOrFail();

            if (! $u->isOpen()) {
                throw new InvalidArgumentException('That payment has already been ' . $u->status . '.');
            }

            $u->forceFill(['status' => 'refunded', 'resolved_by' => auth()->id(), 'resolved_at' => now(), 'resolution_note' => $note])->save();

            AdminAuditLog::record('payment.unapplied_refunded', '₹' . number_format((float) $u->amount, 2)
                . " received online recorded as refunded: {$note}", null,
                array_filter(['account_id' => $u->account_id, 'gateway_payment_id' => $u->gateway_payment_id], fn ($v) => $v !== null));

            $unapplied->setRawAttributes($u->getAttributes(), true);

            return $u;
        }));
    }

    /** @return array{outcome: string, row: null, unapplied: UnappliedPayment} */
    private function unappliedOutcome(array $payment, ?BillingCharge $charge, ?string $accountId, string $reason, ?string $event): array
    {
        return ['outcome' => 'unapplied', 'row' => null, 'unapplied' => $this->recordUnapplied($payment, $charge, $accountId, $reason, $event)];
    }

    /**
     * E3-B — a person (operator, or applying unapplied money) must not settle or waive
     * a bill the owner may be paying online this very minute: that is how one bill
     * gets paid twice. The gateway itself is exempt — it IS that payment.
     */
    private function assertNotBeingPaidOnline(BillingCharge $row, array $input): void
    {
        if (($input['source'] ?? 'operator') === 'gateway' || ! $row->isBeingPaidOnline()) {
            return;
        }

        throw new InvalidArgumentException("{$row->number} is being paid online right now (checkout opened at "
            . $row->gateway_checkout_at->timezone(config('billing.timezone', 'Asia/Kolkata'))->format('H:i')
            . '). Give it a few minutes — if their payment does not arrive, record it then.');
    }

    // ---------------------------------------------------------------
    // Reverse — the payment is struck through; the debt is visible again
    // ---------------------------------------------------------------

    /**
     * FB-20 — reversing a payment reopens what it paid for. Access is NOT touched
     * (an existing decision: "wrong amount, re-enter it" and "refunded, cut access"
     * are different intents). The charge keeps the coverage it granted (E2-G), so
     * re-paying it records the money without granting the same time again.
     */
    public function reverse(SubscriptionInvoice $invoice, string $reason): SubscriptionInvoice
    {
        return MoneyWrite::transaction(function () use ($invoice, $reason) {
            $sub = $this->lockSubscriptionOf($invoice->account_id);

            $reversed = $this->payments->reverse($invoice, $reason);

            if (! $invoice->charge_id) {
                return $reversed;   // a pre-engine row or a free-standing comp: nothing to reopen
            }

            MoneyWrite::during(function () use ($invoice, $reason, $sub) {
                $charge = BillingCharge::query()->whereKey($invoice->charge_id)->lockForUpdate()->first();

                // Only the payment that SETTLED it reopens it; a second reversal of an
                // older row of an already-reopened charge changes nothing.
                if (! $charge || $charge->status !== 'paid') {
                    return;
                }

                // No open_key on a reopened charge: its purpose's key may have been
                // taken since (edge 11) and the clock has moved on anyway.
                $charge->forceFill(['status' => 'pending', 'paid_at' => null, 'reopened_at' => now(), 'open_key' => null])->save();

                AdminAuditLog::record('charge.reopened', "{$charge->number} is owed again (₹" . number_format((float) $charge->amount, 2)
                    . ") — its payment {$invoice->receipt_no} was reversed: {$reason}. Access unchanged.",
                    $charge->tenant_id ?? $sub?->tenant_id, ['account_id' => $charge->account_id, 'charge' => $charge->number, 'receipt_no' => $invoice->receipt_no]);
            });

            return $reversed;
        });
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * A-10 — the account's subscription, locked by its PRIMARY KEY.
     *
     * Every engine path locks this row through the primary key (lifecycle, engine).
     * Locking it through the account_id index instead takes the locks in a
     * different order inside InnoDB, and two such paths deadlocked each other on
     * MariaDB (found by the E2 race test). Resolve the id first, then lock by key.
     */
    private function lockSubscriptionOf(?string $accountId): ?Subscription
    {
        $id = $accountId ? Subscription::withoutGlobalScopes()->where('account_id', $accountId)->value('id') : null;

        return $id ? Subscription::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first() : null;
    }

    /** @return array{0: Subscription, 1: BillingCharge} both locked, subscription first */
    private function lockForSettle(BillingCharge $charge): array
    {
        $sub = $this->lockSubscriptionOf($charge->account_id);

        if (! $sub) {
            throw new InvalidArgumentException('This customer has no subscription.');
        }

        $row = BillingCharge::query()->whereKey($charge->getKey())->lockForUpdate()->firstOrFail();

        if (! $row->isPending()) {
            throw new InvalidArgumentException("{$row->number} is already {$row->statusLabel()} — nothing was recorded. Reload to see the latest.");
        }

        return [$sub, $row];
    }

    /**
     * The coverage this charge buys, written by the lifecycle — once (E2-G).
     *
     * @return array{0: ?Carbon, 1: ?Carbon} [granted start, granted end], nulls when nothing new is granted
     */
    private function grant(Subscription $sub, BillingCharge $charge, string $action, string $reason, bool $free, array $input): array
    {
        if ($charge->hasGranted()) {
            return [null, null];   // reopened after a reversal: the time was already given
        }

        if ($charge->kind === 'branch_join') {
            // The branch is already counted (quantity rose when it joined); this pays
            // for the part period it has been riding. No clock change.
            return [null, null];
        }

        return $this->lifecycle->grantTerm($sub, $charge, $action, $reason, $free, $input);
    }

    private function markPaid(BillingCharge $row, ?Carbon $grantStart, ?Carbon $grantEnd): void
    {
        $fields = ['status' => 'paid', 'paid_at' => now(), 'open_key' => null];

        // E2-G — the first grant is remembered; a re-payment after a reversal keeps it.
        if ($grantEnd !== null) {
            $fields['granted_start'] = $grantStart?->toDateString();
            $fields['granted_end'] = $grantEnd->toDateString();
        }

        MoneyWrite::during(fn () => $row->forceFill($fields)->save());
    }

    private function amountFor(BillingCharge $charge, array $payment): float
    {
        $given = $payment['amount'] ?? null;

        return ($given !== null && $given !== '') ? round((float) $given, 2) : (float) $charge->amount;
    }

    /** D-4 / FB-09 — more than the bill is never silent: the operator must confirm it. */
    private function assertNotOverWithoutConfirm(BillingCharge $charge, float $amount, array $payment): void
    {
        $bill = (float) $charge->amount;

        if ($amount <= $bill + 0.005 || filter_var($payment['confirm_over'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        throw new InvalidArgumentException(self::overMessage($amount, $bill));
    }

    /** The D-4 warning, word for word the same in the preview and in the refusal. */
    public static function overMessage(float $amount, float $bill): string
    {
        $times = $bill > 0 ? round($amount / $bill, 1) : null;

        return '₹ ' . number_format($amount, 2) . ' is MORE than the bill of ₹ ' . number_format($bill, 2)
            . ($times && $times >= 1.5 ? " ({$times}×)" : '')
            . '. If that is really what they paid, tick "I confirm this amount". To pay for more time, choose more months instead.';
    }

    private function paidAt(mixed $given): ?Carbon
    {
        if ($given === null || $given === '') {
            return null;   // the recorder stamps now()
        }

        $tz = config('billing.timezone', 'Asia/Kolkata');
        $date = $given instanceof Carbon ? $given->copy() : Carbon::parse((string) $given, $tz);

        // The DAY it was paid, on the billing clock. A gateway timestamp is a UTC
        // instant: taking its day in UTC and comparing it with today in IST
        // refused real payments for 5½ hours a day (found while building E3).
        if ($date->copy()->timezone($tz)->startOfDay()->greaterThan(Carbon::today($tz))) {
            throw new InvalidArgumentException('A payment cannot be dated in the future.');
        }

        return $date;
    }

    /**
     * REQ-15 — a one-off bargain against THIS bill, recorded, never absorbed.
     *
     * @return array{list_amount?: float, discount_reason?: string}
     */
    private function bargain(float $bill, float $charged, array $input): array
    {
        if ($charged >= $bill || $bill <= 0) {
            return [];
        }

        return [
            'list_amount' => $bill,
            'discount_reason' => trim((string) ($input['discount_reason'] ?? ''))
                ?: (trim((string) ($input['reason'] ?? '')) ?: 'Agreed at the counter'),
        ];
    }
}
