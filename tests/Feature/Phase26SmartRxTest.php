<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EyeRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session 3 — FT-SmartRx (5.2): the "Examined by" name (checked_by) is now
 * persisted, defaulting to the staff member's name when left blank. (OD→OS
 * mirroring + near/add derivation are client-side; validated manually.)
 */
class Phase26SmartRxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin', 'name' => 'Dr Meera']);
        $this->customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Rahul', 'phone' => '+91 9000011111']);
    }

    public function test_checked_by_is_persisted_when_provided(): void
    {
        $this->actingAs($this->user)->post(route('tenant.eye-records.store', $this->customer), [
            'od_sph' => -2.0, 'checked_by' => 'Dr Visiting Optom',
        ])->assertRedirect();

        $this->assertDatabaseHas('eye_records', [
            'customer_id' => $this->customer->id, 'checked_by' => 'Dr Visiting Optom',
        ]);
    }

    public function test_checked_by_defaults_to_the_staff_name_when_blank(): void
    {
        $this->actingAs($this->user)->post(route('tenant.eye-records.store', $this->customer), [
            'od_sph' => -1.5, // no checked_by supplied
        ])->assertRedirect();

        $this->assertDatabaseHas('eye_records', [
            'customer_id' => $this->customer->id, 'checked_by' => 'Dr Meera',
        ]);
    }

    public function test_examiner_is_shown_on_the_customer_timeline(): void
    {
        EyeRecord::create([
            'tenant_id' => $this->user->tenant_id, 'customer_id' => $this->customer->id,
            'recorded_by' => $this->user->id, 'checked_by' => 'Dr Meera', 'od_sph' => -2.0,
        ]);

        $this->actingAs($this->user)->get(route('tenant.customers.show', $this->customer))
            ->assertOk()->assertSee('Examined by Dr Meera');
    }
}
