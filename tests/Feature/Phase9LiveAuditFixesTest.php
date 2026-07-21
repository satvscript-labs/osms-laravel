<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the live-audit fixes (QA_TESTING_REPORT_2.md Section 1).
 * NB-001/002/007 are inline front-end JS and are verified via `npm run build` + manual check.
 */
class Phase9LiveAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function storeUser(?Tenant $tenant = null): User
    {
        $tenant ??= Tenant::create(['store_name' => 'Test Optical']);
        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
    }

    /** NB-003 — free-text / no-digit phone is rejected. */
    public function test_patient_phone_rejects_garbage(): void
    {
        $user = $this->storeUser();

        $this->actingAs($user)->post(route('tenant.customers.store'), [
            'name' => 'Bad Phone', 'country_code' => '+91', 'phone' => 'abc-invalid-phone',
        ])->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('customers', 0);
    }

    /** NB-003 — a valid number with a non-default country code is normalised and stored. */
    public function test_patient_phone_accepts_country_code(): void
    {
        $user = $this->storeUser();

        $this->actingAs($user)->post(route('tenant.customers.store'), [
            'name' => 'Good Phone', 'country_code' => '+1', 'phone' => '5551234567', 'data_consent' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'phone' => '+1 5551234567',
            'tenant_id' => $user->tenant_id,
        ]);
    }

    /** Phone must be exactly 10 digits — too many or too few is rejected. */
    public function test_patient_phone_must_be_ten_digits(): void
    {
        $user = $this->storeUser();

        $this->actingAs($user)->post(route('tenant.customers.store'), [
            'name' => 'Too Long', 'country_code' => '+91', 'phone' => '98765432101', 'data_consent' => '1',
        ])->assertSessionHasErrors('phone');

        $this->actingAs($user)->post(route('tenant.customers.store'), [
            'name' => 'Too Short', 'country_code' => '+91', 'phone' => '987654321', 'data_consent' => '1',
        ])->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('customers', 0);
    }

    /** NB-004 — below-cost pricing is allowed (warn-only), not blocked. */
    public function test_selling_below_cost_is_allowed(): void
    {
        $user = $this->storeUser();

        $this->actingAs($user)->post(route('tenant.inventory.store'), [
            'item_type' => 'frame', 'brand' => 'Clearance', 'model_name' => 'X',
            'cost_price' => 9999, 'selling_price' => 100, 'stock_qty' => 5, 'min_alert_qty' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect(route('tenant.inventory.index'));

        $this->assertDatabaseHas('inventory', [
            'tenant_id' => $user->tenant_id, 'cost_price' => 9999, 'selling_price' => 100,
        ]);
    }

    /** NB-005 — a fully blank eye record is rejected. */
    public function test_blank_eye_record_is_rejected(): void
    {
        $user = $this->storeUser();
        $this->actingAs($user);
        $customer = Customer::create(['name' => 'Priya', 'phone' => '+91 9876543210']);

        $this->actingAs($user)->post(route('tenant.eye-records.store', $customer), [])
            ->assertSessionHasErrors('od_sph');

        $this->assertDatabaseCount('eye_records', 0);
    }

    /** NB-005 — one measurement is enough to save. */
    public function test_eye_record_with_one_measurement_saves(): void
    {
        $user = $this->storeUser();
        $this->actingAs($user);
        $customer = Customer::create(['name' => 'Priya', 'phone' => '+91 9876543211']);

        $this->actingAs($user)->post(route('tenant.eye-records.store', $customer), ['od_sph' => -1.5])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseCount('eye_records', 1);
    }

    /** NB-016 — the dashboard "Scan barcode" shortcut lands on a scan-ready inventory page. */
    public function test_inventory_scan_shortcut_renders(): void
    {
        $user = $this->storeUser();

        $this->actingAs($user)->get(route('tenant.inventory.index', ['scan' => 1]))
            ->assertOk()
            ->assertSee('Ready to scan');
    }
}
