<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase7BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Test Optical']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
        // The trial subscription is auto-created by the Tenant `created` hook (ST-Enforce).
    }

    public function test_billing_page_renders_with_plans(): void
    {
        $this->actingAs($this->user)->get(route('tenant.billing.index'))
            ->assertOk()->assertSee('Basic')->assertSee('Pro')->assertSee('Enterprise');
    }

    public function test_subscribe_without_keys_shows_friendly_error(): void
    {
        config(['services.razorpay.key' => null, 'services.razorpay.secret' => null]);

        $this->actingAs($this->user)->post(route('tenant.billing.subscribe'), ['tier' => 'pro'])
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        config(['services.razorpay.webhook_secret' => 'whsec_test']);

        $this->postJson(route('webhooks.razorpay'), ['event' => 'subscription.activated'], [
            'X-Razorpay-Signature' => 'wrong',
        ])->assertStatus(400);
    }

    public function test_webhook_activates_subscription_with_valid_signature(): void
    {
        config(['services.razorpay.webhook_secret' => 'whsec_test']);

        $sub = Subscription::withoutGlobalScopes()->first();
        $sub->update(['razorpay_subscription_id' => 'sub_ABC123']);

        $payloadArray = [
            'event' => 'subscription.activated',
            'payload' => ['subscription' => ['entity' => [
                'id' => 'sub_ABC123',
                'current_end' => now()->addMonth()->timestamp,
            ]]],
        ];
        $payload = json_encode($payloadArray);
        $signature = hash_hmac('sha256', $payload, 'whsec_test');

        $this->call('POST', route('webhooks.razorpay'), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Razorpay-Signature' => $signature],
            $payload
        )->assertOk();

        $this->assertSame('active', $sub->fresh()->status);
    }
}
