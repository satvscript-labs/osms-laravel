<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Removing a store.
 *
 * The case that matters is the one that has actually bitten us: `users` is the
 * only tenant-owned table with `nullOnDelete` rather than a cascade, so deleting
 * a tenant strands its staff instead of removing them — and their email then
 * blocks that person from signing up again.
 */
class Phase62RemoveTenantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $doomed;

    private Tenant $bystander;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doomed = Tenant::create(['store_name' => 'Doomed Optical']);
        $this->bystander = Tenant::create(['store_name' => 'Bystander Optical']);

        foreach ([$this->doomed, $this->bystander] as $t) {
            User::factory()->create(['tenant_id' => $t->id, 'role' => 'store_admin']);
            $customer = Customer::withoutGlobalScopes()->create([
                'tenant_id' => $t->id, 'name' => 'A Customer', 'phone' => '+91 98240' . random_int(10000, 99999),
            ]);
            Order::withoutGlobalScopes()->create([
                'tenant_id' => $t->id, 'customer_id' => $customer->id,
                'status' => 'delivered', 'subtotal' => 100, 'total_amount' => 100, 'advance_paid' => 100,
            ]);
        }
    }

    private function remove(array $options = []): int
    {
        return $this->artisan('osms:remove-tenant', array_merge(['--force' => true], $options))->run();
    }

    public function test_it_deletes_the_store_and_all_its_data(): void
    {
        $this->assertSame(0, $this->remove(['--id' => $this->doomed->id]));

        $this->assertNull(Tenant::find($this->doomed->id));
        $this->assertSame(0, Customer::withoutGlobalScopes()->where('tenant_id', $this->doomed->id)->count());
        $this->assertSame(0, Order::withoutGlobalScopes()->where('tenant_id', $this->doomed->id)->count());
    }

    /** The regression this command exists for. */
    public function test_users_are_deleted_not_stranded_with_a_null_tenant(): void
    {
        $email = User::withoutGlobalScopes()->where('tenant_id', $this->doomed->id)->value('email');

        $this->remove(['--id' => $this->doomed->id]);

        $this->assertSame(0, User::withoutGlobalScopes()->where('tenant_id', $this->doomed->id)->count());
        $this->assertNull(
            User::withoutGlobalScopes()->where('email', $email)->first(),
            'the user must be gone entirely, not left with a NULL tenant_id blocking a re-signup',
        );
    }

    public function test_another_store_is_untouched(): void
    {
        $this->remove(['--id' => $this->doomed->id]);

        $this->assertNotNull(Tenant::find($this->bystander->id));
        $this->assertSame(1, User::withoutGlobalScopes()->where('tenant_id', $this->bystander->id)->count());
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('tenant_id', $this->bystander->id)->count());
        $this->assertSame(1, Order::withoutGlobalScopes()->where('tenant_id', $this->bystander->id)->count());
    }

    public function test_a_superadmin_is_never_touched(): void
    {
        $super = User::factory()->create(['tenant_id' => null, 'role' => 'superadmin']);

        $this->remove(['--id' => $this->doomed->id]);

        $this->assertNotNull(User::withoutGlobalScopes()->find($super->id));
    }

    public function test_it_refuses_without_an_id(): void
    {
        $this->assertSame(1, $this->remove());
        $this->assertNotNull(Tenant::find($this->doomed->id));
    }

    public function test_an_unknown_id_deletes_nothing(): void
    {
        $this->assertSame(1, $this->remove(['--id' => '00000000-0000-0000-0000-000000000000']));
        $this->assertSame(2, Tenant::count());
    }

    /** Without --force the store name must be typed back exactly. */
    public function test_a_mistyped_confirmation_aborts(): void
    {
        $this->artisan('osms:remove-tenant', ['--id' => $this->doomed->id])
            ->expectsQuestion('Type the store name exactly to confirm', 'doomed optical')
            ->assertFailed();

        $this->assertNotNull(Tenant::find($this->doomed->id), 'a near-miss must not delete anything');
    }

    public function test_an_exact_confirmation_proceeds(): void
    {
        $this->artisan('osms:remove-tenant', ['--id' => $this->doomed->id])
            ->expectsQuestion('Type the store name exactly to confirm', 'Doomed Optical')
            ->assertSuccessful();

        $this->assertNull(Tenant::find($this->doomed->id));
    }
}
