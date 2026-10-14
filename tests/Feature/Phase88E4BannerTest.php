<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * E4.5 — the in-app reminder: the renewal is announced before it is due, with the
 * amount and who can pay it; grace says when the store stops (16_E4_PLAN.md §4).
 */
class Phase88E4BannerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $hq;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Plan::where('code', 'basic')->update(['monthly_price' => 499, 'yearly_price' => 4999, 'price_tiers' => null]);
        \Tests\Fakes\FakeRazorpay::install($this->app);   // online payment configured
        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00', config('billing.timezone')));

        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        $this->owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin', 'name' => 'Asha Owner']);
        $this->hq = app(StoreProvisioner::class)->provision($this->owner, ['store_name' => 'Banner Optical']);
        $this->owner = $this->owner->fresh();
        $this->ends('2026-11-06');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sub(?Tenant $of = null): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', ($of ?? $this->hq)->account_id)->firstOrFail();
    }

    private function ends(string $date, string $status = 'active'): void
    {
        SubscriptionLifecycle::writing(fn () => $this->sub()->forceFill(['status' => $status, 'interval' => 'monthly', 'current_period_end' => $date])->save());
    }

    private function page(User $as)
    {
        return $this->actingAs($as->fresh())->get(route('tenant.dashboard'))->assertOk();
    }

    public function test_the_owner_sees_the_renewal_coming_with_the_amount_and_pay_now(): void
    {
        app(BillingEngine::class)->raiseTerm($this->sub(), ['via' => 'system']);

        $this->page($this->owner)
            ->assertSee('Your renewal of', false)->assertSee('₹ 499.00')->assertSee('is due on 06 Nov 2026')
            ->assertSee('Pay now');
    }

    public function test_without_a_bill_yet_the_quoted_amount_is_shown(): void
    {
        $this->page($this->owner)->assertSee('₹ 499.00')->assertSee('Pay now');
    }

    public function test_tomorrow_and_today_read_as_such(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', config('billing.timezone')));
        $this->page($this->owner)->assertSee('is due tomorrow, 06 Nov');

        Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00', config('billing.timezone')));
        $this->page($this->owner)->assertSee('is due today');
    }

    public function test_more_than_seven_days_out_says_nothing(): void
    {
        $this->ends('2026-11-09');

        $this->page($this->owner)->assertDontSee('Your renewal of');
    }

    public function test_a_branch_admin_is_told_the_owner_pays_and_staff_are_not_bothered_yet(): void
    {
        $branchAdmin = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        app(StoreProvisioner::class)->provision($branchAdmin, ['store_name' => 'Banner B2'], $this->hq->account->fresh());
        $staff = User::factory()->create(['tenant_id' => $this->hq->id, 'role' => 'staff']);

        $this->page($branchAdmin)->assertSee('Asha Owner, the account owner, can pay it online')->assertDontSee('Pay now');
        $this->page($staff)->assertDontSee('Your renewal of');
    }

    public function test_grace_tells_everyone_when_the_store_stops(): void
    {
        $this->ends('2026-10-30');   // ended two days ago; grace runs to 6 Nov
        $staff = User::factory()->create(['tenant_id' => $this->hq->id, 'role' => 'staff']);

        $this->page($this->owner)
            ->assertSee('Your plan ended on')->assertSee('30 Oct 2026')
            ->assertSee('by <strong>06 Nov 2026</strong>', false)->assertSee('Pay now')
            ->assertDontSee("didn't go through", false);

        $this->page($staff)->assertSee('Your plan ended on')->assertSee('Please let your store admin know.');
    }

    public function test_without_online_payment_nobody_is_sent_to_a_pay_button_that_is_not_there(): void
    {
        config(['services.razorpay.key' => null, 'services.razorpay.secret' => null]);
        $this->app->forgetInstance(\App\Services\BillingService::class);   // the real gateway: no keys

        $this->page($this->owner)->assertSee('Your renewal of', false)->assertSee('Our team handles your billing')->assertDontSee('Pay now');
    }

    public function test_a_supervised_account_is_told_how_to_pay(): void
    {
        $this->hq->account->update(['supervised' => true]);

        $this->page($this->owner)->assertSee('Our team handles your billing')->assertDontSee('Pay now');
    }

    public function test_a_cycle_a_person_decided_about_shows_no_amount_to_chase(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        $this->ends('2026-10-01');
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', config('billing.timezone')));
        app(SubscriptionLifecycle::class)->commit($this->sub(), 'comp', ['months' => 1, 'reason' => 'launch offer']);   // → 1 Nov
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00', config('billing.timezone')));

        $this->page($this->owner)
            ->assertSee('Your plan ended on')->assertSee('keeps working until')
            ->assertDontSee('₹ 499.00')->assertDontSee('Pay now');
    }

    public function test_a_cancel_scheduled_shows_no_renewal(): void
    {
        app(SubscriptionLifecycle::class)->ownerCancelAtPeriodEnd($this->sub());

        $this->page($this->owner)->assertDontSee('Your renewal of');
    }

    public function test_one_customer_never_sees_anothers_bill(): void
    {
        $otherOwner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        $other = app(StoreProvisioner::class)->provision($otherOwner, ['store_name' => 'Other']);
        SubscriptionLifecycle::writing(fn () => $this->sub($other)->forceFill(['status' => 'active', 'interval' => 'yearly', 'current_period_end' => '2026-11-06'])->save());
        app(BillingEngine::class)->raiseTerm($this->sub($other), ['via' => 'system']);   // ₹4,999

        $this->page($this->owner)->assertSee('₹ 499.00')->assertDontSee('4,999');
        $this->page($otherOwner)->assertSee('₹ 4,999.00')->assertDontSee('₹ 499.00');
    }
}
