<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FT-WhatsApp req 2 — premium call + WhatsApp contact buttons on the customer
 * page. Always manual (opened from the staff member's own device) and wholly
 * independent of the store's automated-messaging mode.
 */
class Phase33CustomerContactTest extends TestCase
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

    public function test_contact_helpers_build_normalised_links(): void
    {
        $c = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Rahul', 'phone' => '+91 9876543210']);

        $this->assertSame('https://wa.me/919876543210', $c->whatsappUrl());
        $this->assertSame('tel:+919876543210', $c->telHref());
    }

    public function test_customer_page_renders_both_contact_buttons(): void
    {
        $c = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Rahul', 'phone' => '+91 9876543210']);

        $this->actingAs($this->user)
            ->get(route('tenant.customers.show', $c))
            ->assertOk()
            ->assertSee('https://wa.me/919876543210', false)
            ->assertSee('tel:+919876543210', false);
    }

    public function test_buttons_hidden_for_an_unusable_number(): void
    {
        $c = Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Bad', 'phone' => '+91 12']);

        $this->assertNull($c->whatsappUrl());
        $this->assertNull($c->telHref());

        $this->actingAs($this->user)
            ->get(route('tenant.customers.show', $c))
            ->assertOk()
            ->assertDontSee('wa.me/', false);
    }
}
