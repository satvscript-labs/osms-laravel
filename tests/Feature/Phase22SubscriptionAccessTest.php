<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ST-Enforce (S1) — subscription access enforcement.
 * The workspace must depend on a live subscription; expired trials, cancelled,
 * and lapsed-beyond-grace subs are hard-locked to billing.
 */
class Phase22SubscriptionAccessTest extends TestCase
{
    use RefreshDatabase;

    private function storeFor(array|false $subscription = []): array
    {
        $tenant = Tenant::create(['store_name' => 'Access Optical']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);

        // A trialing subscription is auto-created by the Tenant `created` hook.
        $sub = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        if ($subscription === false) {
            $sub?->delete(); // simulate a store with no subscription row
        } elseif ($subscription !== []) {
            $sub->update($subscription);
        }

        return [$tenant, $user];
    }

    // ---- accessState() unit coverage -------------------------------------

    public function test_access_state_active_for_in_window_trial(): void
    {
        $sub = new Subscription(['status' => 'trialing', 'current_period_end' => now()->addDays(5)]);
        $this->assertSame('active', $sub->accessState());
        $this->assertTrue($sub->hasAccess());
    }

    public function test_access_state_locked_for_expired_trial(): void
    {
        $sub = new Subscription(['status' => 'trialing', 'current_period_end' => now()->subDays(2)]);
        $this->assertSame('locked', $sub->accessState());
        $this->assertFalse($sub->hasAccess());
    }

    public function test_access_state_grace_for_recent_past_due(): void
    {
        $sub = new Subscription(['status' => 'past_due', 'current_period_end' => now()->subDays(3)]);
        $this->assertSame('grace', $sub->accessState());
        $this->assertTrue($sub->isInGracePeriod());
    }

    public function test_access_state_locked_for_past_due_beyond_grace(): void
    {
        $sub = new Subscription(['status' => 'past_due', 'current_period_end' => now()->subDays(20)]);
        $this->assertSame('locked', $sub->accessState());
    }

    public function test_access_state_locked_for_canceled(): void
    {
        $sub = new Subscription(['status' => 'canceled', 'current_period_end' => now()->addDays(30)]);
        $this->assertSame('locked', $sub->accessState());
    }

    public function test_access_state_grace_for_active_past_period(): void
    {
        // Paid sub whose renewal webhook is late → grace, not an instant lock.
        $sub = new Subscription(['status' => 'active', 'current_period_end' => now()->subDays(2)]);
        $this->assertSame('grace', $sub->accessState());
    }

    public function test_trial_days_left_is_null_when_not_trialing(): void
    {
        $sub = new Subscription(['status' => 'active', 'current_period_end' => now()->addDays(5)]);
        $this->assertNull($sub->trialDaysLeft());
    }

    // ---- middleware enforcement ------------------------------------------

    public function test_active_trial_can_reach_the_workspace(): void
    {
        [, $user] = $this->storeFor(['status' => 'trialing', 'current_period_end' => now()->addDays(10)]);

        $this->actingAs($user)->get(route('tenant.dashboard'))->assertOk();
    }

    public function test_expired_trial_is_locked_to_billing(): void
    {
        [, $user] = $this->storeFor(['status' => 'trialing', 'current_period_end' => now()->subDay()]);

        $this->actingAs($user)->get(route('tenant.dashboard'))
            ->assertRedirect(route('tenant.billing.index'));
    }

    public function test_canceled_subscription_is_locked(): void
    {
        [, $user] = $this->storeFor(['status' => 'canceled']);

        $this->actingAs($user)->get(route('tenant.orders.index'))
            ->assertRedirect(route('tenant.billing.index'));
    }

    public function test_grace_period_can_still_use_the_workspace(): void
    {
        [, $user] = $this->storeFor(['status' => 'past_due', 'current_period_end' => now()->subDays(2)]);

        $this->actingAs($user)->get(route('tenant.dashboard'))->assertOk();
    }

    public function test_tenant_with_no_subscription_is_locked(): void
    {
        [, $user] = $this->storeFor(false); // no subscription row at all

        $this->actingAs($user)->get(route('tenant.dashboard'))
            ->assertRedirect(route('tenant.billing.index'));
    }

    public function test_billing_page_is_reachable_while_locked(): void
    {
        [, $user] = $this->storeFor(['status' => 'canceled']);

        $this->actingAs($user)->get(route('tenant.billing.index'))->assertOk();
    }

    public function test_locked_tenant_does_not_leak_into_active_tenant(): void
    {
        // Tenant A is locked; tenant B is active. Enforcement is per-user tenant.
        [, $lockedUser] = $this->storeFor(['status' => 'canceled']);
        [, $activeUser] = $this->storeFor(['status' => 'trialing', 'current_period_end' => now()->addDays(7)]);

        $this->actingAs($lockedUser)->get(route('tenant.dashboard'))
            ->assertRedirect(route('tenant.billing.index'));
        $this->actingAs($activeUser)->get(route('tenant.dashboard'))->assertOk();
    }

    // ---- reconcile command ------------------------------------------------

    public function test_reconcile_flips_expired_trial_and_spares_live_one(): void
    {
        [$tenantExpired] = $this->storeFor(['status' => 'trialing', 'current_period_end' => now()->subDays(3)]);
        [$tenantLive] = $this->storeFor(['status' => 'trialing', 'current_period_end' => now()->addDays(3)]);

        $this->artisan('subscriptions:reconcile')->assertSuccessful();

        $expired = Subscription::withoutGlobalScopes()->where('tenant_id', $tenantExpired->id)->first();
        $live = Subscription::withoutGlobalScopes()->where('tenant_id', $tenantLive->id)->first();

        $this->assertSame('canceled', $expired->status);
        $this->assertSame('trialing', $live->status);
    }
}
