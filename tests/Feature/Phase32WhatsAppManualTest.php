<?php

namespace Tests\Feature;

use App\Enums\MessagingMode;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FT-WhatsApp Phase 3 — the Manual messaging flow (no Meta dependency).
 *
 * A store with no config behaves as a Manual store: order cards show a one-tap
 * "Send on WhatsApp" pill that opens wa.me with the message pre-filled. Off hides
 * it; an Automated-but-unconnected store degrades to the manual pill (never
 * silence). Settings are per-tenant.
 */
class Phase32WhatsAppManualTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'store_admin']);
    }

    private function order(string $status = 'ready_for_pickup', string $phone = '+91 9876543210', ?Tenant $tenant = null): Order
    {
        $tenant ??= $this->tenant;
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Rahul', 'phone' => $phone]);

        return Order::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'fulfillment_type' => 'special',
            'subtotal' => 1000, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
            'total_amount' => 1000, 'advance_paid' => 200,
        ])->load('customer');
    }

    // ---- Phone normalisation ----

    public function test_phone_helper_normalises_stored_shape(): void
    {
        $this->assertSame('919876543210', Phone::waDigits('+91 9876543210'));
        $this->assertSame('+919876543210', Phone::e164('+91 9876543210'));
        // Bare national number gets the default dialling code.
        $this->assertSame('919876543210', Phone::waDigits('9876543210', '+91'));
        // Too short → invalid.
        $this->assertNull(Phone::waDigits('12'));
        $this->assertNull(Phone::waDigits(''));
    }

    // ---- Mode resolution + the pill ----

    public function test_zero_config_store_defaults_to_manual_and_shows_pill(): void
    {
        $config = WhatsAppConfig::defaultFor($this->tenant);
        $order = $this->order('ready_for_pickup');

        $this->assertSame(MessagingMode::Manual, $config->modeFor('order_ready'));

        $pill = $config->orderPill($order);
        $this->assertNotNull($pill);
        $this->assertFalse($pill['fallback']);
        $this->assertStringContainsString('wa.me/919876543210', $pill['url']);
    }

    public function test_pill_message_resolves_placeholders_and_is_url_encoded(): void
    {
        $config = WhatsAppConfig::defaultFor($this->tenant);
        $pill = $config->orderPill($this->order('ready_for_pickup'));

        $text = urldecode((string) parse_url($pill['url'], PHP_URL_QUERY));
        $this->assertStringContainsString('Rahul', $text);        // {name}
        $this->assertStringContainsString('Test Optical', $text); // {store}
        // A raw space must be percent-encoded in the URL (never a literal space).
        $this->assertStringNotContainsString(' ', (string) parse_url($pill['url'], PHP_URL_QUERY));
    }

    public function test_off_mode_hides_pill(): void
    {
        $config = new WhatsAppConfig(['mode' => 'off']);
        $config->tenant_id = $this->tenant->id;
        $config->setRelation('tenant', $this->tenant);

        $this->assertSame(MessagingMode::Off, $config->modeFor('order_ready'));
        $this->assertNull($config->orderPill($this->order('ready_for_pickup')));
    }

    public function test_disabled_event_hides_pill(): void
    {
        $config = WhatsAppConfig::defaultFor($this->tenant);
        $config->on_ready = false;

        $this->assertSame(MessagingMode::Off, $config->modeFor('order_ready'));
        $this->assertNull($config->orderPill($this->order('ready_for_pickup')));
    }

    public function test_invalid_phone_hides_pill(): void
    {
        $config = WhatsAppConfig::defaultFor($this->tenant);
        $this->assertNull($config->orderPill($this->order('ready_for_pickup', '+91 12')));
    }

    public function test_automated_but_unconnected_falls_back_to_manual_pill(): void
    {
        $config = WhatsAppConfig::defaultFor($this->tenant);
        $config->mode = 'automated'; // chosen, but no verified credentials

        $this->assertFalse($config->isReady());
        $this->assertSame(MessagingMode::ManualFallback, $config->modeFor('order_ready'));

        $pill = $config->orderPill($this->order('ready_for_pickup'));
        $this->assertNotNull($pill);          // never silently dropped
        $this->assertTrue($pill['fallback']); // shows the "finish setup" hint
    }

    public function test_status_maps_to_the_right_event(): void
    {
        $config = WhatsAppConfig::defaultFor($this->tenant);
        $this->assertSame('Send receipt', $config->orderPill($this->order('pending', '+91 9000000001'))['label']);
        $this->assertSame('Send thank-you', $config->orderPill($this->order('delivered', '+91 9000000002'))['label']);
        $this->assertNull($config->orderPill($this->order('cancelled', '+91 9000000003'))); // no event for cancelled
    }

    // ---- The orders board renders it ----

    public function test_kanban_board_renders_the_pill(): void
    {
        $this->order('ready_for_pickup');

        $this->actingAs($this->user)
            ->get(route('tenant.orders.index', ['view' => 'kanban']))
            ->assertOk()
            ->assertSee('wa.me/919876543210');
    }

    // ---- Settings ----

    public function test_settings_update_persists_mode_and_wording(): void
    {
        $this->actingAs($this->user)
            ->put(route('tenant.whatsapp.update'), [
                'mode' => 'manual',
                'on_ready' => '1',
                'msg_ready' => 'Hi {name}, custom ready message for {store}.',
                'default_country_code' => '+91',
            ])->assertRedirect();

        $config = WhatsAppConfig::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($config);
        $this->assertSame('manual', $config->mode);
        $this->assertStringContainsString('custom ready message', $config->messageTemplate('order_ready'));
        // An unchecked toggle submits nothing ⇒ stored false.
        $this->assertFalse($config->on_placed);
    }

    public function test_settings_are_tenant_isolated(): void
    {
        // Tenant A already has a config.
        WhatsAppConfig::create(['tenant_id' => $this->tenant->id, 'mode' => 'off']);

        // Tenant B's admin saves their own — must not touch A's row.
        $other = Tenant::create(['store_name' => 'Other Optical', 'tax_id' => 'G2', 'address' => 'Pune']);
        $otherUser = User::factory()->create(['tenant_id' => $other->id, 'role' => 'store_admin']);

        $this->actingAs($otherUser)
            ->put(route('tenant.whatsapp.update'), ['mode' => 'manual', 'default_country_code' => '+91'])
            ->assertRedirect();

        // A's config is untouched; B sees only its own row under the tenant scope.
        $this->assertSame('off', WhatsAppConfig::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->first()->mode);
        $this->assertSame(1, WhatsAppConfig::count()); // scoped to tenant B
        $this->assertSame('manual', WhatsAppConfig::first()->mode);
    }
}
