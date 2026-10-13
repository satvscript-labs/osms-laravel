<?php

namespace App\Services;

use App\Models\BillingCharge;
use App\Models\Tenant;
use Razorpay\Api\Api;
use RuntimeException;

/**
 * Thin wrapper around the Razorpay SDK. Keeps the controller clean and lets the
 * app run locally without keys (isConfigured() guards live calls).
 */
class BillingService
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.razorpay.key'))
            && ! empty(config('services.razorpay.secret'));
    }

    private function api(): Api
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Razorpay keys are not configured.');
        }

        return new Api(config('services.razorpay.key'), config('services.razorpay.secret'));
    }

    /**
     * Create a Razorpay subscription for a tenant on a tier + billing interval.
     * Returns ['subscription_id' => ..., 'short_url' => ...].
     */
    public function createSubscription(Tenant $tenant, string $tier, string $interval = 'monthly'): array
    {
        $planId = config("services.razorpay.plans.$tier.$interval");
        if (! $planId) {
            throw new RuntimeException("No Razorpay plan configured for [$tier / $interval].");
        }

        $subscription = $this->api()->subscription->create([
            'plan_id' => $planId,
            'total_count' => (int) config("billing.cycles.$interval", 12),
            'quantity' => 1,
            'customer_notify' => 1,
            'notes' => ['tenant_id' => $tenant->id, 'tier' => $tier, 'interval' => $interval],
        ]);

        return [
            'subscription_id' => $subscription['id'],
            'short_url' => $subscription['short_url'] ?? null,
        ];
    }

    /**
     * Switch an active subscription to a different billing interval, effective at
     * the next renewal (no mid-cycle proration). The webhook confirms it.
     */
    public function changeInterval(string $razorpaySubscriptionId, string $tier, string $interval): void
    {
        $planId = config("services.razorpay.plans.$tier.$interval");
        if (! $planId) {
            throw new RuntimeException("No Razorpay plan configured for [$tier / $interval].");
        }

        $this->api()->subscription->fetch($razorpaySubscriptionId)->update([
            'plan_id' => $planId,
            'schedule_change_at' => 'cycle_end',
            'customer_notify' => 1,
        ]);
    }

    /** Reverse-map a Razorpay plan id back to its billing interval (for webhooks). */
    public function planInterval(?string $planId): ?string
    {
        if (! $planId) {
            return null;
        }

        foreach ((array) config('services.razorpay.plans') as $intervals) {
            foreach ((array) $intervals as $interval => $configuredId) {
                if ($configuredId === $planId) {
                    return $interval;
                }
            }
        }

        return null;
    }

    /**
     * Cancel a Razorpay subscription — at the end of the current billing cycle by
     * default (access continues until then; the webhook flips status when it
     * ends), or immediately when access has already ended (E1.10: "End access
     * now" and churn must not leave one more charge queued).
     */
    public function cancelSubscription(string $razorpaySubscriptionId, bool $atCycleEnd = true): void
    {
        $this->api()->subscription->fetch($razorpaySubscriptionId)->cancel(['cancel_at_cycle_end' => $atCycleEnd ? 1 : 0]);
    }

    // ---------------------------------------------------------------
    // E3 — the online lane: Orders for bills (D-1 = B). No Plans, no Subscriptions.
    // ---------------------------------------------------------------

    /**
     * A Razorpay Order for exactly this bill's amount — read from the database,
     * never from the browser (rule 3). Captured automatically. The bill's number is
     * the receipt, so the Razorpay dashboard and OSMS name the same thing.
     *
     * @return string the Razorpay order id
     */
    public function createOrder(BillingCharge $charge): string
    {
        $order = $this->api()->order->create([
            'amount' => (int) round((float) $charge->amount * 100),   // paise
            'currency' => 'INR',
            'receipt' => $charge->number,
            'payment_capture' => 1,
            'notes' => [
                'charge_id' => $charge->id,
                'account_id' => $charge->account_id,
                'charge' => $charge->number,
            ],
        ]);

        return (string) $order['id'];
    }

    /**
     * The payment as RAZORPAY recorded it — amount, status, order — never as the
     * browser describes it.
     *
     * @return array{id: string, order_id: ?string, amount: int, currency: string, status: ?string, created_at: ?int, method: ?string}
     */
    public function fetchPayment(string $paymentId): array
    {
        $p = $this->api()->payment->fetch($paymentId)->toArray();

        return [
            'id' => (string) $p['id'],
            'order_id' => $p['order_id'] ?? null,
            'amount' => (int) ($p['amount'] ?? 0),            // paise
            'currency' => (string) ($p['currency'] ?? 'INR'),
            'status' => $p['status'] ?? null,                 // created · authorized · captured · failed · refunded
            'created_at' => isset($p['created_at']) ? (int) $p['created_at'] : null,
            'method' => $p['method'] ?? null,
        ];
    }

    /**
     * The checkout callback's proof: HMAC-SHA256 of "order_id|payment_id" with the
     * key secret (Razorpay's documented scheme; same as the SDK's Utility).
     */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = config('services.razorpay.secret');

        if (! $secret || $orderId === '' || $paymentId === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature);
    }

    /** Verify a Razorpay webhook signature. */
    public function verifyWebhook(string $payload, string $signature): bool
    {
        $secret = config('services.razorpay.webhook_secret');
        if (! $secret) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    public function publicKey(): ?string
    {
        return config('services.razorpay.key');
    }
}
