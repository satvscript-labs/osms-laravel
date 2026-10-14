<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Services\BillingService;
use App\Services\MoneyWrite;
use App\Services\Settlement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E3.3 — Razorpay tells us money moved (06 §6, rule 4).
 *
 * The online lane is Orders (D-1 = B): `order.paid` / `payment.captured` settle the
 * bill the order was for, through the same Settlement as cash — idempotently, so the
 * checkout callback and this webhook can both arrive. Money that matches no open
 * bill is KEPT as unapplied and shown to the operator (FB-04).
 *
 * E3-E — the old Razorpay *subscriptions* lane is retired: `subscription.*` events
 * never change anyone's access; any money they carry is kept as unapplied.
 *
 * E3-G — business outcomes (matched, unapplied, ignored) answer 200. An
 * infrastructure failure answers 500 so Razorpay retries, and the "event handled"
 * mark is rolled back WITH the work: it used to be written first, so a crash midway
 * marked the event done and the retry was skipped.
 */
class RazorpayWebhookController extends Controller
{
    public function handle(Request $request, BillingService $billing, Settlement $settlement): JsonResponse
    {
        $payload = $request->getContent();

        if (! $billing->verifyWebhook($payload, (string) $request->header('X-Razorpay-Signature', ''))) {
            return response()->json(['ok' => false], 400);
        }

        $eventId = $request->header('X-Razorpay-Event-Id');
        $event = (string) $request->input('event', '');

        try {
            MoneyWrite::transaction(function () use ($eventId, $event, $request, $settlement) {
                if ($eventId) {
                    $record = WebhookEvent::firstOrCreate(['id' => $eventId], ['type' => $event]);

                    if (! $record->wasRecentlyCreated) {
                        return;   // already handled
                    }
                }

                $this->process($event, $request, $settlement);
            });
        } catch (UniqueConstraintViolationException) {
            // The same event is being handled by a parallel delivery right now.
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }

    private function process(string $event, Request $request, Settlement $settlement): void
    {
        $payment = $this->payment($request);

        // A-15 — a Razorpay account sends EVERY payment here, including other
        // products' if the account is shared. Only OSMS's money is ours to record.
        if ($payment !== null && in_array($event, ['order.paid', 'payment.captured', 'payment.failed', 'subscription.charged'], true)
            && ! $this->isOsms($event, $payment, $request)) {
            $this->auditNotOurs($event, $payment);

            return;
        }

        match (true) {
            in_array($event, ['order.paid', 'payment.captured'], true) && $payment !== null
                => $settlement->settleGateway($payment, 'webhook', $event),

            $event === 'payment.failed' && $payment !== null => $this->auditFailed($payment, $request),

            $event === 'subscription.charged' && $payment !== null => $this->legacyAutopayMoney($payment, $request, $settlement),

            str_starts_with($event, 'subscription.') => $this->auditLegacy($event, $request),

            default => null,   // payment.authorized, refund.*, anything else: nothing to change
        };
    }

    /**
     * A-15 — is this OSMS's payment? Yes when its order belongs to one of our bills,
     * or it carries the marker every OSMS order is created with (notes.charge_id,
     * receipt "CHG-…"), or — for the retired autopay lane — its subscription is one
     * of ours. Otherwise it belongs to something else on the same Razorpay account.
     */
    private function isOsms(string $event, array $payment, Request $request): bool
    {
        if ($event === 'subscription.charged') {
            $gatewaySub = (string) $request->input('payload.subscription.entity.id', '');

            return $gatewaySub !== '' && Subscription::withoutGlobalScopes()->where('razorpay_subscription_id', $gatewaySub)->exists();
        }

        if ($payment['order_id'] && BillingCharge::query()->where('gateway_order_id', $payment['order_id'])->exists()) {
            return true;
        }

        // F2 (audit) — money we already hold is ours, whatever its bill says now.
        if (\App\Models\SubscriptionInvoice::withoutGlobalScopes()->where('razorpay_payment_id', $payment['id'])->exists()
            || \App\Models\UnappliedPayment::query()->where('gateway_payment_id', $payment['id'])->exists()) {
            return true;
        }

        $orderNotes = (array) $request->input('payload.order.entity.notes', []);
        $paymentNotes = (array) $request->input('payload.payment.entity.notes', []);
        $receipt = (string) $request->input('payload.order.entity.receipt', '');

        return ! empty($orderNotes['charge_id']) || ! empty($paymentNotes['charge_id']) || str_starts_with($receipt, 'CHG-');
    }

    /** A-15 — somebody else's payment on a shared Razorpay account: on the record, never as OSMS money. */
    private function auditNotOurs(string $event, array $payment): void
    {
        AdminAuditLog::record('payment.not_osms', "Razorpay sent {$event} for a payment that is not an OSMS bill ("
            . $payment['id'] . ') — ignored.', null, [
                'gateway_payment_id' => $payment['id'],
                'gateway_order_id' => $payment['order_id'],
                'actor' => 'gateway',
            ]);
    }

    /** The payment entity, shaped as Settlement expects it (amount in paise). */
    private function payment(Request $request): ?array
    {
        $p = $request->input('payload.payment.entity');

        if (! is_array($p) || empty($p['id'])) {
            return null;
        }

        return [
            'id' => (string) $p['id'],
            'order_id' => $p['order_id'] ?? null,
            'amount' => (int) ($p['amount'] ?? 0),
            'currency' => (string) ($p['currency'] ?? 'INR'),
            'status' => $p['status'] ?? null,
            'created_at' => isset($p['created_at']) ? (int) $p['created_at'] : null,
        ];
    }

    /** A failed attempt changes nothing — the owner can simply try again. Recorded for the operator. */
    private function auditFailed(array $payment, Request $request): void
    {
        $bill = $payment['order_id'] ? BillingCharge::query()->where('gateway_order_id', $payment['order_id'])->first() : null;

        AdminAuditLog::record('payment.failed', 'An online payment attempt failed'
            . ($bill ? " for {$bill->number}" : '') . ': '
            . mb_substr((string) $request->input('payload.payment.entity.error_description', 'no reason given'), 0, 200)
            . '. Nothing changed; they can try again.', $bill?->tenant_id, array_filter([
                'account_id' => $bill?->account_id,
                'gateway_payment_id' => $payment['id'],
                'gateway_order_id' => $payment['order_id'],
                'actor' => 'gateway',
            ], fn ($v) => $v !== null));
    }

    /** E3-E — a legacy autopay took money: keep it, flag it, change nothing else. */
    private function legacyAutopayMoney(array $payment, Request $request, Settlement $settlement): void
    {
        $gatewaySub = (string) $request->input('payload.subscription.entity.id', '');
        $sub = $gatewaySub !== ''
            ? Subscription::withoutGlobalScopes()->where('razorpay_subscription_id', $gatewaySub)->first()
            : null;

        if (($payment['status'] ?? null) === null) {
            $payment['status'] = 'captured';   // subscription.charged is only sent for captured money
        }

        $settlement->recordUnapplied($payment, null, $sub?->account_id,
            'Charged by an old Razorpay autopay' . ($gatewaySub !== '' ? " ({$gatewaySub})" : '')
            . ' — OSMS no longer bills by autopay. Apply it to one of their bills, or refund it.', 'subscription.charged');
    }

    /** E3-E — status news from the retired lane: on the record, never acted on. */
    private function auditLegacy(string $event, Request $request): void
    {
        $gatewaySub = (string) $request->input('payload.subscription.entity.id', '');
        $sub = $gatewaySub !== ''
            ? Subscription::withoutGlobalScopes()->where('razorpay_subscription_id', $gatewaySub)->first()
            : null;

        AdminAuditLog::record('subscription.gateway_event_ignored', "Razorpay sent {$event}"
            . ($gatewaySub !== '' ? " for {$gatewaySub}" : '')
            . ' — the autopay lane is retired, so nothing about access changed.', $sub?->tenant_id, array_filter([
                'account_id' => $sub?->account_id,
                'event' => $event,
                'actor' => 'gateway',
            ], fn ($v) => $v !== null));
    }
}
