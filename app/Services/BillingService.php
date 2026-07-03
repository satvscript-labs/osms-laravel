<?php

namespace App\Services;

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
     * Cancel a Razorpay subscription at the end of the current billing cycle
     * (access continues until then; the webhook flips status when it ends).
     */
    public function cancelSubscription(string $razorpaySubscriptionId): void
    {
        $this->api()->subscription->cancel($razorpaySubscriptionId, ['cancel_at_cycle_end' => 1]);
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
