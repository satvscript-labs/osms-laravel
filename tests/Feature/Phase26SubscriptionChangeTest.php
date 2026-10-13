<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Change 2 — subscription self-service: switch billing interval (monthly <->
 * yearly), effective at the next renewal, plus webhook interval reconciliation.
 */
class Phase26SubscriptionChangeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Cycle Optical']);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
        $this->subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->first();
        $this->subscription->update([
            'status' => 'active', 'interval' => 'monthly', 'razorpay_subscription_id' => 'sub_ABC',
        ]);
    }

    public function test_active_subscriber_can_schedule_a_cycle_switch(): void
    {
        // E3-E — no Razorpay plan to switch: the engine prices the next bill at the
        // new period. Nothing may be sent to the gateway.
        $this->mock(BillingService::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldNotReceive('cancelSubscription');
        });

        $this->actingAs($this->admin)->post(route('tenant.billing.change-interval'), ['interval' => 'yearly'])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame('yearly', $this->subscription->fresh()->pending_interval);
    }

    public function test_cycle_switch_rejected_when_not_active(): void
    {
        $this->subscription->update(['status' => 'trialing']);

        $this->actingAs($this->admin)->post(route('tenant.billing.change-interval'), ['interval' => 'yearly'])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertNull($this->subscription->fresh()->pending_interval);
    }

    public function test_cycle_switch_rejected_when_same_interval(): void
    {
        $this->actingAs($this->admin)->post(route('tenant.billing.change-interval'), ['interval' => 'monthly'])
            ->assertSessionHas('error');
    }

    /**
     * E3-E — the retired autopay lane no longer switches anyone's billing period: a
     * pending switch lands only when they pay their next renewal (E1.4). The money
     * in the charge is kept for the operator.
     */
    public function test_a_legacy_webhook_never_switches_the_billing_period(): void
    {
        config([
            'services.razorpay.webhook_secret' => 'whsec_test',
            'services.razorpay.plans.basic.yearly' => 'plan_Y',
        ]);
        $this->subscription->update(['pending_interval' => 'yearly']);

        $body = [
            'event' => 'subscription.charged',
            'payload' => [
                'subscription' => ['entity' => ['id' => 'sub_ABC', 'plan_id' => 'plan_Y', 'current_end' => now()->addYear()->timestamp]],
                'payment' => ['entity' => ['id' => 'pay_cycle', 'amount' => 499900, 'currency' => 'INR']],
            ],
        ];
        $payload = json_encode($body);
        $sig = hash_hmac('sha256', $payload, 'whsec_test');

        $this->call('POST', route('webhooks.razorpay'), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Razorpay-Signature' => $sig, 'HTTP_X-Razorpay-Event-Id' => 'evt_cycle'],
            $payload
        )->assertOk();

        $fresh = $this->subscription->fresh();
        $this->assertNotSame('yearly', $fresh->interval, 'the running period is untouched');
        $this->assertSame('yearly', $fresh->pending_interval, 'the switch still waits for the renewal');
        $this->assertSame(4999.0, (float) \App\Models\UnappliedPayment::where('gateway_payment_id', 'pay_cycle')->value('amount'));
    }
}
