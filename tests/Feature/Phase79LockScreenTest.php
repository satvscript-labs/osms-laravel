<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\StoreClosure;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISS-01 — what a locked-out store is shown, and where it is sent.
 *
 * Two defects found by hand-testing, both about the same confusion: the code
 * asked the SUBSCRIPTION why access was denied, when the answer often lives on
 * the TENANT (closure, per-store suspension) or in an operator override.
 *
 *   • A closed store bounced between the lock screen and the dashboard forever
 *     — the customer's whole product was a browser error page.
 *   • A store the operator suspended was told "your payment is overdue, please
 *     renew" while being fully paid up.
 */
class Phase79LockScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->admin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
    }

    /** @return array{0: Tenant, 1: User} a paid-up store and its owner */
    private function paidStore(string $name = 'Alpha Optical'): array
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $tenant = app(StoreProvisioner::class)->provision($owner, ['store_name' => $name]);

        $tenant->account->subscription->forceFill([
            'status' => 'active',
            'interval' => 'monthly',
            'current_period_end' => now()->addDays(300),
        ])->save();

        return [$tenant->fresh(), $owner->fresh()];
    }

    private function asOperator(): self
    {
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | The redirect loop
    |--------------------------------------------------------------------------
    */

    public function test_a_closed_store_lands_on_our_own_page_not_a_browser_error(): void
    {
        [$tenant, $owner] = $this->paidStore('Beta Optical');
        app(StoreClosure::class)->close($tenant, 'shop sold');

        // Follow it manually: a loop shows up as a redirect that never resolves.
        $response = $this->actingAs($owner)->get(route('tenant.dashboard'));
        $response->assertRedirect(route('tenant.locked'));

        $this->actingAs($owner)->get(route('tenant.locked'))
            ->assertOk()
            ->assertSee('This store has been closed');
    }

    public function test_a_closed_store_owner_is_never_bounced_back_to_the_dashboard(): void
    {
        [$tenant, $owner] = $this->paidStore('Gamma Optical');
        app(StoreClosure::class)->close($tenant, 'closed');

        // The loop's cause: closure lives on the tenant, so the subscription is
        // still perfectly active and `hasAccess()` said everything was fine.
        $this->assertTrue($tenant->account->subscription->hasAccess());

        $this->actingAs($owner)->followingRedirects()
            ->get(route('tenant.dashboard'))
            ->assertOk()
            ->assertSee('This store has been closed');
    }

    public function test_a_closed_store_cannot_reach_the_checkout(): void
    {
        [$tenant, $owner] = $this->paidStore('Delta Optical');
        app(StoreClosure::class)->close($tenant, 'closed');

        // Taking money for a relationship you have ended is worse than a dead end.
        $this->actingAs($owner)->get(route('tenant.billing.index'))
            ->assertRedirect(route('tenant.locked'));
    }

    /*
    |--------------------------------------------------------------------------
    | Saying what is actually true
    |--------------------------------------------------------------------------
    */

    public function test_a_suspended_store_is_not_told_to_pay_again(): void
    {
        [$tenant, $owner] = $this->paidStore();

        $this->asOperator();
        app(SubscriptionLifecycle::class)
            ->commit($tenant->account->subscription, 'suspend', ['reason' => 'dispute']);

        $response = $this->actingAs($owner)->followingRedirects()->get(route('tenant.dashboard'));

        $response->assertOk()
            ->assertSee('Your access has been paused')
            ->assertSee('not a billing problem', false)
            // They are paid up. Inviting a second payment for something no
            // payment can fix creates a refund conversation, not a solution.
            ->assertDontSee('Subscribe to continue')
            ->assertDontSee('payment is overdue');
    }

    public function test_a_suspended_store_is_given_a_way_to_reach_us(): void
    {
        config(['saas.support_email' => 'help@example.com']);
        [$tenant, $owner] = $this->paidStore();

        $this->asOperator();
        app(SubscriptionLifecycle::class)
            ->commit($tenant->account->subscription, 'suspend', ['reason' => 'dispute']);

        $this->actingAs($owner)->get(route('tenant.locked'))
            ->assertOk()
            ->assertSee('help@example.com');
    }

    public function test_the_operators_internal_reason_is_never_shown_to_the_customer(): void
    {
        [$tenant, $owner] = $this->paidStore();

        $this->asOperator();
        app(SubscriptionLifecycle::class)->commit(
            $tenant->account->subscription, 'suspend',
            ['reason' => 'suspected fraud, chargeback risk'],
        );

        // That note was written for the audit trail, not addressed to them.
        $this->actingAs($owner)->get(route('tenant.locked'))
            ->assertOk()
            ->assertDontSee('suspected fraud');
    }

    public function test_an_operator_cancellation_says_so_rather_than_inviting_a_payment(): void
    {
        [$tenant, $owner] = $this->paidStore();

        $this->asOperator();
        app(SubscriptionLifecycle::class)
            ->commit($tenant->account->subscription, 'force_expire', ['reason' => 'ended']);

        $this->actingAs($owner)->followingRedirects()->get(route('tenant.dashboard'))
            ->assertOk()
            ->assertSee('Your subscription has been ended')
            ->assertDontSee('Subscribe to continue');
    }

    /*
    |--------------------------------------------------------------------------
    | The states a customer CAN fix must still work exactly as before
    |--------------------------------------------------------------------------
    */

    public function test_an_expired_trial_still_sends_the_admin_to_billing(): void
    {
        [$tenant, $owner] = $this->paidStore('Trial Shop');
        $tenant->account->subscription->forceFill([
            'status' => 'trialing',
            'current_period_end' => now()->subDays(3),
        ])->save();

        $this->actingAs($owner)->get(route('tenant.dashboard'))
            ->assertRedirect(route('tenant.billing.index'));

        // And the checkout must stay reachable — this is the one case where
        // paying genuinely lifts the block.
        $this->actingAs($owner)->get(route('tenant.billing.index'))->assertOk();
    }

    public function test_an_overdue_payment_still_sends_the_admin_to_billing(): void
    {
        [$tenant, $owner] = $this->paidStore('Overdue Shop');
        $tenant->account->subscription->forceFill([
            'status' => 'past_due',
            'current_period_end' => now()->subDays(60),
        ])->save();

        $this->actingAs($owner)->get(route('tenant.dashboard'))
            ->assertRedirect(route('tenant.billing.index'));
    }

    public function test_staff_still_get_the_lock_screen_rather_than_a_403(): void
    {
        [$tenant] = $this->paidStore('Staff Shop');
        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'staff']);
        $tenant->account->subscription->forceFill([
            'status' => 'trialing',
            'current_period_end' => now()->subDays(3),
        ])->save();

        // SEC-03 — billing is admin-only, so sending staff there is a dead end.
        $this->actingAs($staff)->followingRedirects()->get(route('tenant.dashboard'))
            ->assertOk()
            ->assertSee('Your free trial has ended');
    }

    public function test_a_healthy_store_is_never_stranded_on_the_lock_screen(): void
    {
        [, $owner] = $this->paidStore('Healthy Shop');

        $this->actingAs($owner)->get(route('tenant.locked'))
            ->assertRedirect(route('tenant.dashboard'));
    }

    /*
    |--------------------------------------------------------------------------
    | The reason itself
    |--------------------------------------------------------------------------
    */

    public function test_the_lock_reason_is_answered_in_one_place(): void
    {
        [$tenant] = $this->paidStore('Reason Shop');
        $this->assertNull($tenant->accessDenialReason());

        $this->asOperator();
        app(SubscriptionLifecycle::class)
            ->commit($tenant->account->subscription, 'suspend', ['reason' => 'x']);
        $this->assertSame('suspended', $tenant->fresh()->accessDenialReason());
        $this->assertFalse($tenant->fresh()->lockIsSelfResolvable());

        app(SubscriptionLifecycle::class)
            ->commit($tenant->account->subscription->fresh(), 'reactivate', ['reason' => 'sorted']);
        $this->assertNull($tenant->fresh()->accessDenialReason());

        app(StoreClosure::class)->close($tenant->fresh(), 'closed');
        $this->assertSame('closed', $tenant->fresh()->accessDenialReason());
        $this->assertFalse($tenant->fresh()->lockIsSelfResolvable());
    }
}
