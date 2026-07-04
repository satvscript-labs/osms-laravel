<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session 3 — FT-Birthday (5.5): new customers can capture a birthday; age is
 * derived from it (always current) and stored for records. A plain age remains
 * the optional fallback when no birthday is given.
 */
class Phase25BirthdayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['store_name' => 'Test Optical', 'tax_id' => 'GST', 'address' => 'Mumbai']);
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);
    }

    public function test_birthday_persists_and_derives_age(): void
    {
        $bday = now()->subYears(30)->subDays(10)->toDateString();

        $this->actingAs($this->user)->post(route('tenant.customers.store'), [
            'name' => 'Rahul', 'country_code' => '+91', 'phone' => '9876543210',
            'birthday' => $bday,
        ])->assertRedirect();

        $c = Customer::first();
        $this->assertSame($bday, $c->birthday->toDateString());
        $this->assertSame(30, $c->age); // derived from the birthday
    }

    public function test_age_fallback_when_no_birthday(): void
    {
        $this->actingAs($this->user)->post(route('tenant.customers.store'), [
            'name' => 'Anjali', 'country_code' => '+91', 'phone' => '9988776655',
            'age' => 45,
        ])->assertRedirect();

        $c = Customer::first();
        $this->assertNull($c->birthday);
        $this->assertSame(45, $c->age);
    }

    public function test_birthday_wins_over_a_stored_age(): void
    {
        $c = Customer::create([
            'tenant_id' => $this->user->tenant_id, 'name' => 'Both', 'phone' => '+91 9000000000',
            'age' => 99, 'birthday' => now()->subYears(25)->subDays(3),
        ]);

        $this->assertSame(25, $c->fresh()->age); // birthday overrides the stale 99
    }

    public function test_future_birthday_is_rejected(): void
    {
        $this->actingAs($this->user)->post(route('tenant.customers.store'), [
            'name' => 'Future', 'country_code' => '+91', 'phone' => '9111100000',
            'birthday' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('birthday');

        $this->assertSame(0, Customer::count());
    }
}
