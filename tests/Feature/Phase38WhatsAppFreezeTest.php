<?php

namespace Tests\Feature;

use App\Enums\MessagingMode;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FT-WhatsApp — the Automated freeze must hold at RUNTIME, not just in settings.
 *
 * Regression guard: a store whose stored mode is still `automated` (set before the
 * freeze, or restored from a non-production database) must degrade to the manual
 * pill. Otherwise the pill is hidden, messages are scheduled, and — with the
 * default `log` driver — they vanish into a log file while staff believe the
 * customer was messaged.
 */
class Phase38WhatsAppFreezeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Prod Optical', 'tax_id' => 'G', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    private function legacyAutomatedStore(): WhatsAppConfig
    {
        return WhatsAppConfig::create([
            'tenant_id' => $this->tenant->id,
            'mode' => 'automated',
            'enabled' => true,
            'verified_at' => now(),
            'phone_number_id' => '123456',
            'access_token' => 'tok',
        ]);
    }

    private function readyOrder(): Order
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Rahul', 'phone' => '+91 9876543210']);

        return Order::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'status' => 'ready_for_pickup', 'fulfillment_type' => 'special',
            'subtotal' => 1000, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
            'total_amount' => 1000, 'advance_paid' => 200,
        ])->load('customer', 'tenant');
    }

    public function test_frozen_automated_store_degrades_to_the_manual_pill(): void
    {
        config(['whatsapp.automated_enabled' => false]);
        $this->actingAs($this->user);

        $config = $this->legacyAutomatedStore();
        $order = $this->readyOrder();

        $this->assertFalse($config->isReady());
        $this->assertSame(MessagingMode::ManualFallback, $config->modeFor('order_ready'));

        // The staff must still have a way to message the customer.
        $pill = $config->orderPill($order);
        $this->assertNotNull($pill, 'Manual pill must remain available while Automated is frozen.');
        $this->assertStringContainsString('wa.me/919876543210', $pill['url']);
    }

    public function test_frozen_automated_store_schedules_nothing(): void
    {
        config(['whatsapp.automated_enabled' => false]);
        $this->actingAs($this->user);

        $this->legacyAutomatedStore();
        app(WhatsAppScheduler::class)->handle($this->readyOrder(), 'order_ready');

        $this->assertSame(0, WhatsAppMessage::count(), 'A frozen store must never schedule a send.');
    }

    public function test_unfreezing_restores_automated(): void
    {
        config(['whatsapp.automated_enabled' => true, 'whatsapp.driver' => 'log']);
        $this->actingAs($this->user);

        $config = $this->legacyAutomatedStore();

        $this->assertTrue($config->isReady());
        $this->assertSame(MessagingMode::Automated, $config->modeFor('order_ready'));
    }

    public function test_log_driver_never_counts_as_connected_in_production(): void
    {
        // Even if Automated were switched on, a credential-less log driver in
        // production must not read as "connected" — that would swallow messages.
        config(['whatsapp.automated_enabled' => true, 'whatsapp.driver' => 'log']);
        app()->detectEnvironment(fn () => 'production');

        $config = WhatsAppConfig::create([
            'tenant_id' => $this->tenant->id,
            'mode' => 'automated',
            'enabled' => true,
            'verified_at' => now(),
        ]);

        $this->assertFalse($config->hasCredentials());
        $this->assertFalse($config->isReady());
    }
}
