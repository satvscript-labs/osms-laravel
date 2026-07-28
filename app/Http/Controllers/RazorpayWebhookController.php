<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\WebhookEvent;
use App\Services\BillingService;
use App\Services\PaymentRecorder;
use App\Services\SubscriptionLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RazorpayWebhookController extends Controller
{
    public function handle(
        Request $request,
        BillingService $billing,
        SubscriptionLifecycle $lifecycle,
    ): JsonResponse {
        $payload = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature', '');

        if (! $billing->verifyWebhook($payload, $signature)) {
            return response()->json(['ok' => false], 400);
        }

        // Idempotency — process each Razorpay event at most once.
        $eventId = $request->header('X-Razorpay-Event-Id');
        if ($eventId) {
            $record = WebhookEvent::firstOrCreate(
                ['id' => $eventId],
                ['type' => $request->input('event')],
            );

            if (! $record->wasRecentlyCreated) {
                return response()->json(['ok' => true]); // already handled
            }
        }

        $event = $request->input('event');
        $sub = $request->input('payload.subscription.entity', []);
        $razorpayId = $sub['id'] ?? null;

        // Record a payment receipt on every successful charge.
        if ($event === 'subscription.charged') {
            $this->recordInvoice($request, $razorpayId);
        }

        if (! $razorpayId) {
            return response()->json(['ok' => true]); // nothing else actionable
        }

        // Find the subscription without the tenant scope (webhook is unauthenticated).
        $subscription = Subscription::withoutGlobalScopes()
            ->where('razorpay_subscription_id', $razorpayId)
            ->first();

        if (! $subscription) {
            return response()->json(['ok' => true]);
        }

        $status = match ($event) {
            'subscription.activated', 'subscription.charged', 'subscription.resumed' => 'active',
            'subscription.pending', 'subscription.halted' => 'past_due',
            'subscription.cancelled', 'subscription.completed' => 'canceled',
            default => null,
        };

        // BUG-P01 — entitlement is mutated in exactly one place, which knows about
        // operator overrides. Note the payment was already recorded above: money that
        // changed hands is always written to the ledger, even when the operator has
        // overruled what it means for access.
        $lifecycle->applyGatewayEntitlement(
            $subscription,
            $status,
            ! empty($sub['current_end']) ? Carbon::createFromTimestamp($sub['current_end']) : null,
            $billing->planInterval($sub['plan_id'] ?? null),
        );

        return response()->json(['ok' => true]);
    }

    /** Persist a subscription payment as an invoice (idempotent by payment id). */
    private function recordInvoice(Request $request, ?string $razorpaySubId): void
    {
        $payment = $request->input('payload.payment.entity', []);
        $paymentId = $payment['id'] ?? null;

        if (! $paymentId || ! $razorpaySubId) {
            return;
        }

        $subscription = Subscription::withoutGlobalScopes()
            ->where('razorpay_subscription_id', $razorpaySubId)
            ->first();

        if (! $subscription) {
            return;
        }

        // P4 — the automated lane writes through the SAME recorder as the
        // manual one, so both produce identical rows (receipt number included)
        // and idempotency lives in one place. Previously this wrote the row
        // directly and skipped `receipt_no`, so a customer who paid online got
        // a receipt with no number while a cash payer got one.
        app(PaymentRecorder::class)->recordGatewayPayment(
            $subscription,
            $paymentId,
            ($payment['amount'] ?? 0) / 100, // paise → rupees
            [
                'razorpay_subscription_id' => $razorpaySubId,
                'currency' => $payment['currency'] ?? 'INR',
                'paid_at' => ! empty($payment['created_at'])
                    ? Carbon::createFromTimestamp($payment['created_at'])
                    : now(),
            ],
        );
    }
}
