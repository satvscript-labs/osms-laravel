<?php

namespace App\Services;

use App\Models\BillingCharge;
use Razorpay\Api\Api;
use RuntimeException;

/**
 * The Razorpay gateway (E3, D-1 = B): Orders for bills, payments fetched from
 * Razorpay, signatures verified. No Plans and no Subscriptions — the engine
 * prices everything. `cancelSubscription` remains only to stop a LEGACY autopay
 * on an ending (E1.10). Runs locally without keys (isConfigured() guards calls).
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
