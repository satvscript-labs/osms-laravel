<?php

namespace Tests\Fakes;

use App\Models\BillingCharge;
use App\Services\BillingService;
use RuntimeException;

/**
 * E3 — Razorpay without the network. Orders get predictable ids; payments are
 * whatever the test says Razorpay recorded; signatures are made with the same
 * secrets the app verifies with, so the real verification code is exercised.
 *
 * Install with `FakeRazorpay::install($this->app)` — it sets test secrets and
 * binds itself as the app's BillingService.
 */
class FakeRazorpay extends BillingService
{
    public const KEY = 'rzp_test_FAKEKEY';
    public const SECRET = 'fake_key_secret';
    public const WEBHOOK_SECRET = 'fake_webhook_secret';

    /** @var list<array{charge: string, amount: int, order: string}> */
    public array $ordersCreated = [];

    /** @var array<string, array> payment id → what Razorpay has recorded */
    public array $payments = [];

    /** @var list<array{0: string, 1: bool}> */
    public array $cancelled = [];

    public bool $down = false;

    private int $seq = 0;

    public static function install($app): self
    {
        config([
            'services.razorpay.key' => self::KEY,
            'services.razorpay.secret' => self::SECRET,
            'services.razorpay.webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        $fake = new self();
        $app->instance(BillingService::class, $fake);

        return $fake;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createOrder(BillingCharge $charge): string
    {
        if ($this->down) {
            throw new RuntimeException('Razorpay is unreachable (fake).');
        }

        $id = 'order_FAKE' . str_pad((string) ++$this->seq, 6, '0', STR_PAD_LEFT);
        $this->ordersCreated[] = ['charge' => $charge->id, 'amount' => (int) round((float) $charge->amount * 100), 'order' => $id];

        return $id;
    }

    public function fetchPayment(string $paymentId): array
    {
        if ($this->down) {
            throw new RuntimeException('Razorpay is unreachable (fake).');
        }

        if (! isset($this->payments[$paymentId])) {
            throw new RuntimeException("No such payment {$paymentId} (fake).");
        }

        return $this->payments[$paymentId];
    }

    public function cancelSubscription(string $razorpaySubscriptionId, bool $atCycleEnd = true): void
    {
        $this->cancelled[] = [$razorpaySubscriptionId, $atCycleEnd];
    }

    /** Razorpay has recorded a payment on an order (status captured by default). */
    public function pay(string $orderId, float $rupees, string $status = 'captured', ?string $paymentId = null): string
    {
        $paymentId ??= 'pay_FAKE' . str_pad((string) ++$this->seq, 6, '0', STR_PAD_LEFT);

        $this->payments[$paymentId] = [
            'id' => $paymentId,
            'order_id' => $orderId,
            'amount' => (int) round($rupees * 100),
            'currency' => 'INR',
            'status' => $status,
            'created_at' => now()->timestamp,
            'method' => 'upi',
        ];

        return $paymentId;
    }

    /** What Razorpay Checkout posts back to the callback, correctly signed. */
    public static function callback(string $orderId, string $paymentId): array
    {
        return [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => hash_hmac('sha256', $orderId . '|' . $paymentId, self::SECRET),
        ];
    }

    /** A webhook body and the headers Razorpay sends with it, correctly signed. */
    public static function webhook(string $event, array $payload, ?string $eventId = null): array
    {
        $body = json_encode(['event' => $event, 'payload' => $payload]);

        return [$body, [
            'X-Razorpay-Signature' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
            'X-Razorpay-Event-Id' => $eventId ?? ('evt_' . bin2hex(random_bytes(6))),
            'Content-Type' => 'application/json',
        ]];
    }
}
