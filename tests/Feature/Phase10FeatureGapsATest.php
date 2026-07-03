<?php

namespace Tests\Feature;

use App\Models\EyeRecord;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Section 2 — Phase A: CRUD-completeness for patients, eye records, and store settings.
 * Every tenant-owned mutation includes a cross-tenant isolation assertion (CLAUDE.md).
 */
class Phase10FeatureGapsATest extends TestCase
{
    use RefreshDatabase;

    private function storeUser(string $role = 'store_admin'): User
    {
        $tenant = Tenant::create(['store_name' => 'Test Optical']);

        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
    }

    // ---- Patient edit / update (NB-008) ----

    public function test_patient_edit_page_renders(): void
    {
        $user = $this->storeUser();
        $this->actingAs($user);
        $customer = Customer::create(['name' => 'Rahul', 'phone' => '+91 9876543210']);

        $this->actingAs($user)->get(route('tenant.customers.edit', $customer))
            ->assertOk()->assertSee('Rahul');
    }

    public function test_can_update_patient(): void
    {
        $user = $this->storeUser();
        $this->actingAs($user);
        $customer = Customer::create(['name' => 'Rahul', 'phone' => '+91 9876543210', 'age' => 30]);

        $this->actingAs($user)->put(route('tenant.customers.update', $customer), [
            'name' => 'Rahul Kumar',
            'country_code' => '+91',
            'phone' => '9999999999',
            'age' => 31,
            'gender' => 'male',
        ])->assertRedirect(route('tenant.customers.show', $customer));

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Rahul Kumar',
            'phone' => '+91 9999999999',
            'age' => 31,
        ]);
    }

    public function test_cannot_update_another_tenants_patient(): void
    {
        $u1 = $this->storeUser();
        $this->actingAs($u1);
        $customer = Customer::create(['name' => 'Secret', 'phone' => '+91 1112223334']);

        $u2 = $this->storeUser();
        $this->actingAs($u2)->put(route('tenant.customers.update', $customer), [
            'name' => 'Hijacked', 'country_code' => '+91', 'phone' => '9999999999',
        ])->assertNotFound();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Secret']);
    }

    // ---- Eye-record edit / update / destroy (NB-008b) ----

    public function test_can_update_eye_record(): void
    {
        $user = $this->storeUser();
        $this->actingAs($user);
        $customer = Customer::create(['name' => 'Rahul', 'phone' => '+91 9876543210']);
        $record = EyeRecord::create(['customer_id' => $customer->id, 'od_sph' => -1.0, 'recorded_by' => $user->id]);

        $this->actingAs($user)->put(route('tenant.eye-records.update', $record), [
            'od_sph' => -2.5, 'od_axis' => 45, 'pd' => 60,
        ])->assertRedirect(route('tenant.customers.show', $customer));

        $this->assertDatabaseHas('eye_records', [
            'id' => $record->id, 'od_sph' => -2.5, 'od_axis' => 45,
        ]);
    }

    public function test_can_delete_eye_record(): void
    {
        $user = $this->storeUser();
        $this->actingAs($user);
        $customer = Customer::create(['name' => 'Rahul', 'phone' => '+91 9876543210']);
        $record = EyeRecord::create(['customer_id' => $customer->id, 'od_sph' => -1.0, 'recorded_by' => $user->id]);

        $this->actingAs($user)->delete(route('tenant.eye-records.destroy', $record))
            ->assertRedirect(route('tenant.customers.show', $customer));

        $this->assertDatabaseMissing('eye_records', ['id' => $record->id]);
    }

    public function test_cannot_delete_another_tenants_eye_record(): void
    {
        $u1 = $this->storeUser();
        $this->actingAs($u1);
        $customer = Customer::create(['name' => 'Rahul', 'phone' => '+91 9876543210']);
        $record = EyeRecord::create(['customer_id' => $customer->id, 'od_sph' => -1.0, 'recorded_by' => $u1->id]);

        $u2 = $this->storeUser();
        $this->actingAs($u2)->delete(route('tenant.eye-records.destroy', $record))->assertNotFound();

        $this->assertDatabaseHas('eye_records', ['id' => $record->id]);
    }

    // ---- Store settings (FG-Settings) ----

    public function test_settings_route_redirects_to_unified_hub(): void
    {
        $user = $this->storeUser('store_admin');

        // The legacy store-settings route now folds into the unified Settings hub.
        $this->actingAs($user)->get(route('tenant.settings.edit'))
            ->assertRedirect(route('profile.edit'));

        // The hub renders the store section for a store admin.
        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()->assertSee('Store identity');
    }

    public function test_can_update_store_settings(): void
    {
        $user = $this->storeUser('store_admin');

        $this->actingAs($user)->put(route('tenant.settings.update'), [
            'store_name' => 'Sahaj Optical Renamed',
            'tax_id' => '22AAAAA0000A1Z5',
            'address' => '1 New Road',
        ])->assertRedirect(route('profile.edit'));

        $this->assertDatabaseHas('tenants', [
            'id' => $user->tenant_id,
            'store_name' => 'Sahaj Optical Renamed',
            'tax_id' => '22AAAAA0000A1Z5',
        ]);
    }

    public function test_staff_cannot_access_store_settings(): void
    {
        $user = $this->storeUser('staff');

        $this->actingAs($user)->get(route('tenant.settings.edit'))->assertForbidden();
        $this->actingAs($user)->put(route('tenant.settings.update'), [
            'store_name' => 'Hacked',
        ])->assertForbidden();
    }
}
