<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\WebhookEvent;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RazorpayWebhookController extends Controller
{
    public function handle(Request $request, BillingService $billing): JsonResponse
    {
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

        if ($status) {
            $subscription->status = $status;
            if ($status === 'canceled') {
                $subscription->cancel_at_period_end = false; // the cancel has taken effect
            }
        }

        if (! empty($sub['current_end'])) {
            $subscription->current_period_end = Carbon::createFromTimestamp($sub['current_end']);
        }

        // Reflect the billing interval from the charged plan; clear a pending switch once applied.
        if ($interval = $billing->planInterval($sub['plan_id'] ?? null)) {
            $subscription->interval = $interval;
            if ($subscription->pending_interval === $interval) {
                $subscription->pending_interval = null;
            }
        }

        $subscription->save();

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

        SubscriptionInvoice::withoutGlobalScopes()->firstOrCreate(
            ['razorpay_payment_id' => $paymentId],
            [
                'tenant_id' => $subscription->tenant_id,
                'razorpay_subscription_id' => $razorpaySubId,
                'amount' => ($payment['amount'] ?? 0) / 100, // paise → rupees
                'currency' => $payment['currency'] ?? 'INR',
                'status' => 'paid',
                'paid_at' => ! empty($payment['created_at'])
                    ? Carbon::createFromTimestamp($payment['created_at'])
                    : now(),
            ],
        );
    }
}
