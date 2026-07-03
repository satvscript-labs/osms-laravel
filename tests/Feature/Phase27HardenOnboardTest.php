<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bunch 5 — ST-Harden (security headers) + ST-Onboard (trial messaging,
 * first-run guidance).
 */
class Phase27HardenOnboardTest extends TestCase
{
    use RefreshDatabase;

    // ---- ST-Harden -------------------------------------------------------

    public function test_security_headers_are_present(): void
    {
        $this->get(route('home'))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    // ---- ST-Onboard ------------------------------------------------------

    public function test_onboarding_shows_trial_terms(): void
    {
        $user = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        $this->actingAs($user)->get(route('onboarding.create'))
            ->assertOk()->assertSee('free trial');
    }

    public function test_dashboard_shows_first_run_for_empty_store(): void
    {
        [$tenant, $user] = $this->store();

        $this->actingAs($user)->get(route('tenant.dashboard'))
            ->assertOk()->assertSee("get your store ready");
    }

    public function test_dashboard_hides_first_run_once_data_exists(): void
    {
        [$tenant, $user] = $this->store();
        Inventory::create([
            'tenant_id' => $tenant->id, 'sku' => 'SKU1', 'barcode' => '123456789012',
            'item_type' => 'frame', 'brand' => 'Ray-Ban', 'model_name' => 'Aviator',
            'cost_price' => 50, 'selling_price' => 250, 'stock_qty' => 5, 'min_alert_qty' => 2,
        ]);

        $this->actingAs($user)->get(route('tenant.dashboard'))
            ->assertOk()->assertDontSee("get your store ready");
    }

    private function store(): array
    {
        $tenant = Tenant::create(['store_name' => 'Fresh Optical']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);

        return [$tenant, $user];
    }
}
