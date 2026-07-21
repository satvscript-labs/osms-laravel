<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-03 / UX-07 — when a store's subscription lapses, STAFF must land on an
 * explanatory lock screen rather than the 403 they used to get (billing is
 * admin-only). Admins still go to billing so they can pay.
 */
class Phase49LockedStoreTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['store_name' => 'Locked Optical', 'tax_id' => 'G', 'address' => 'Jaipur']);
        // Expire the auto-created trial.
        $this->tenant->subscription->update([
            'status' => 'trialing',
            'current_period_end' => now()->subDays(5),
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => $role]);
    }

    public function test_locked_staff_are_sent_to_the_lock_screen_not_a_403(): void
    {
        $staff = $this->user('staff');

        $this->actingAs($staff)->get(route('tenant.dashboard'))
            ->assertRedirect(route('tenant.locked'));

        $this->actingAs($staff)->get(route('tenant.locked'))
            ->assertOk()
            ->assertSee('Workspace paused');
    }

    public function test_lock_screen_names_an_admin_who_can_renew(): void
    {
        $admin = $this->user('store_admin');
        $staff = $this->user('staff');

        $this->actingAs($staff)->get(route('tenant.locked'))
            ->assertOk()
            ->assertSee($admin->email);
    }

    public function test_locked_admin_still_goes_to_billing(): void
    {
        $admin = $this->user('store_admin');

        $this->actingAs($admin)->get(route('tenant.dashboard'))
            ->assertRedirect(route('tenant.billing.index'));
    }

    public function test_admin_hitting_the_lock_screen_is_redirected_to_billing(): void
    {
        $admin = $this->user('store_admin');

        $this->actingAs($admin)->get(route('tenant.locked'))
            ->assertRedirect(route('tenant.billing.index'));
    }

    public function test_an_active_store_is_not_stranded_on_the_lock_screen(): void
    {
        $this->tenant->subscription->update(['status' => 'active', 'current_period_end' => now()->addDays(30)]);
        $staff = $this->user('staff');

        $this->actingAs($staff)->get(route('tenant.locked'))
            ->assertRedirect(route('tenant.dashboard'));
    }
}
