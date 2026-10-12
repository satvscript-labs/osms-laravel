<?php

namespace Tests\Feature;

use App\Services\BillingService;
use Tests\TestCase;

/**
 * E1 — the safety rails around the billing engine (09_E1_PLAN.md).
 *
 *   E1.0  the test suite can never reach the real payment gateway (FB-17)
 */
class Phase84E1SafetyTest extends TestCase
{
    public function test_the_suite_never_holds_gateway_credentials(): void
    {
        // The developer's .env holds a LIVE Razorpay key; phpunit.xml forces it
        // empty. If this ever fails, an unmocked path would charge real cards.
        $this->assertEmpty(config('services.razorpay.key'));
        $this->assertEmpty(config('services.razorpay.secret'));
        $this->assertEmpty(config('services.razorpay.webhook_secret'));
        $this->assertFalse(app(BillingService::class)->isConfigured());
    }
}
