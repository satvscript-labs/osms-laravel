<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WhatsApp refocus + polish:
 *  - Automated mode frozen as "Coming soon" in production (config-gated).
 *  - Pending-dues WhatsApp reminder link.
 *  - Customers "Birthdays" tab (next 7 days) + daysUntilBirthday accessor.
 *  - "SPL" prescription measurement removed everywhere.
 */
class Phase36WhatsAppRefocusTest extends TestCase
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

    private function dueOrder(string $phone = '+91 9876543210'): Order
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Rahul', 'phone' => $phone]);

        return Order::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'status' => 'ready_for_pickup',
            'fulfillment_type' => 'special',
            'subtotal' => 1000, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0,
            'total_amount' => 1000, 'advance_paid' => 200,
        ])->load('customer');
    }

    // ---- Automated mode lock (production) ----

    public function test_automated_mode_rejected_when_frozen(): void
    {
        config(['whatsapp.automated_enabled' => false]);

        $this->actingAs($this->user)
            ->put(route('tenant.whatsapp.update'), ['mode' => 'automated', 'default_country_code' => '+91'])
            ->assertSessionHasErrors('mode', null, 'whatsappSettings');

        // Manual is always allowed.
        $this->actingAs($this->user)
            ->put(route('tenant.whatsapp.update'), ['mode' => 'manual', 'default_country_code' => '+91'])
            ->assertRedirect();
    }

    public function test_automated_mode_allowed_when_enabled(): void
    {
        config(['whatsapp.automated_enabled' => true]);

        $this->actingAs($this->user)
            ->put(route('tenant.whatsapp.update'), ['mode' => 'automated', 'default_country_code' => '+91'])
            ->assertRedirect();

        $this->assertSame('automated', WhatsAppConfig::where('tenant_id', $this->tenant->id)->first()->mode);
    }

    public function test_settings_shows_coming_soon_when_frozen(): void
    {
        config(['whatsapp.automated_enabled' => false]);

        $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Soon')                     // the disabled segment badge
            ->assertDontSee('Send test');           // the connection/test card is hidden
    }

    // ---- Pending-dues WhatsApp reminder ----

    public function test_dues_reminder_url_is_prefilled_with_balance(): void
    {
        $config = WhatsAppConfig::defaultFor($this->tenant);
        $url = $config->duesReminderUrl($this->dueOrder());

        $this->assertNotNull($url);
        $this->assertStringContainsString('wa.me/919876543210', $url);
        $text = urldecode((string) parse_url($url, PHP_URL_QUERY));
        $this->assertStringContainsString('Rahul', $text);
        $this->assertStringContainsString('800', $text); // balance_due = 1000 - 200
    }

    public function test_dues_reminder_url_null_for_bad_phone(): void
    {
        $config = WhatsAppConfig::defaultFor($this->tenant);
        $this->assertNull($config->duesReminderUrl($this->dueOrder('+91 12')));
    }

    public function test_analytics_dues_tab_renders_reminder_pill(): void
    {
        $this->dueOrder();

        $this->actingAs($this->user)
            ->get(route('tenant.analytics.index'))
            ->assertOk()
            ->assertSee('wa.me/919876543210');
    }

    // ---- Birthdays ----

    public function test_upcoming_birthday_scope_and_accessor(): void
    {
        $near = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Soon',
            'phone' => '+91 9000000001', 'birthday' => now()->addDays(3)->subYears(30)->toDateString(),
        ]);
        $far = Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Later',
            'phone' => '+91 9000000002', 'birthday' => now()->addDays(200)->subYears(30)->toDateString(),
        ]);

        $this->assertSame(3, $near->daysUntilBirthday());

        $ids = Customer::upcomingBirthday(7)->pluck('id');
        $this->assertTrue($ids->contains($near->id));
        $this->assertFalse($ids->contains($far->id));
    }

    public function test_birthdays_filter_returns_json_with_days(): void
    {
        Customer::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cake',
            'phone' => '+91 9000000003', 'birthday' => now()->addDays(2)->subYears(25)->toDateString(),
        ]);

        $this->actingAs($this->user)
            ->getJson(route('tenant.customers.index', ['filter' => 'birthdays']))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Cake', 'days_until_birthday' => 2]);
    }

    // ---- SPL removal ----

    public function test_eye_record_form_has_no_spl(): void
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Rx', 'phone' => '+91 9000000004']);

        $this->actingAs($this->user)
            ->get(route('tenant.eye-records.create', $customer))
            ->assertOk()
            ->assertDontSee('od_spl', false)
            ->assertDontSee('>SPL<', false);
    }

    public function test_eye_record_saves_without_spl(): void
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Rx', 'phone' => '+91 9000000005']);

        $this->actingAs($this->user)
            ->post(route('tenant.eye-records.store', $customer), ['od_sph' => 1.5])
            ->assertRedirect();

        $this->assertDatabaseHas('eye_records', ['customer_id' => $customer->id, 'od_sph' => 1.5]);
    }
}
