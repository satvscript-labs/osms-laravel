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

        match (true) {
            in_array($event, ['order.paid', 'payment.captured'], true) && $payment !== null
                => $settlement->settleGateway($payment, 'webhook', $event),

            $event === 'payment.failed' && $payment !== null => $this->auditFailed($payment, $request),

            $event === 'subscription.charged' && $payment !== null => $this->legacyAutopayMoney($payment, $request, $settlement),

            str_starts_with($event, 'subscription.') => $this->auditLegacy($event, $request),

            default => null,   // payment.authorized, refund.*, anything else: nothing to change
        };
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
