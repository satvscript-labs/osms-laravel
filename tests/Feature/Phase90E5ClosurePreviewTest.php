<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\StoreClosure;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * E5.1 — closing a branch says what it does to the bill before anyone confirms
 * (FB-14, E5-A): the next renewal before → after, a tier change, the open renewal
 * bill, a branch's part-period debt, and the last open store as the ending it is.
 */
class Phase90E5ClosurePreviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $hq;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Plan::where('code', 'basic')->update(['monthly_price' => 499, 'yearly_price' => 4999, 'price_tiers' => null]);
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        $this->hq = app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']), ['store_name' => 'Close HQ']);
        Subscription::withoutGlobalScopes()->where('account_id', $this->hq->account_id)->firstOrFail()
            ->forceFill(['status' => 'active', 'interval' => 'monthly', 'current_period_end' => Carbon::today(config('billing.timezone'))->addDays(20)])->save();
    }

    private function branch(string $name): Tenant
    {
        return app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']), ['store_name' => $name], $this->hq->account->fresh());
    }

    private function page()
    {
        return $this->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('superadmin.accounts.show', $this->hq->account_id))->assertOk();
    }

    public function test_closing_one_of_two_branches_shows_the_next_renewal_before_and_after(): void
    {
        $b2 = $this->branch('Close B2');
        app(BillingEngine::class)->raiseTerm($this->hq->account->subscription, ['via' => 'system']);

        $impact = app(StoreClosure::class)->impact($b2->fresh());
        $this->assertSame([998.0, 499.0, false, false], [$impact['before']['effective'], $impact['after']['effective'], $impact['last_open'], $impact['tier_changes']]);
        $this->assertNotNull($impact['open_renewal']);
        $this->assertGreaterThan(0, $impact['branch_owed'], 'the branch still owes its part period');

        $this->page()
            ->assertSee('₹ 998.00/mo</strong>', false)->assertSee('₹ 499.00/mo</strong>', false)
            ->assertSee('will be re-priced to match')
            ->assertSee('for its part period stays owed');
    }

    public function test_a_tier_change_is_named(): void
    {
        Plan::where('code', 'basic')->firstOrFail()->update(['price_tiers' => [
            ['from' => 1, 'to' => 1, 'monthly' => 499, 'yearly' => 4999],
            ['from' => 2, 'to' => null, 'monthly' => 449, 'yearly' => 4499],
        ]]);
        $b2 = $this->branch('Tier B2');

        $impact = app(StoreClosure::class)->impact($b2->fresh());

        $this->assertTrue($impact['tier_changes']);
        $this->assertSame([449.0, 499.0], [$impact['before']['unit_price'], $impact['after']['unit_price']]);
        $this->page()->assertSee('The per-branch rate changes: ₹ 449.00')->assertSee('₹ 499.00 for the branches that stay');
    }

    public function test_a_branch_not_billed_changes_nothing_and_says_so(): void
    {
        $b2 = $this->branch('Free B2');
        $b2->fresh()->update(['is_billable' => false]);

        $this->assertFalse(app(StoreClosure::class)->impact($b2->fresh())['billable']);
        $this->page()->assertSee('This store is not billed, so closing it changes nothing about what they pay.');
    }

    public function test_the_last_open_store_reads_as_an_ending_and_offers_cancelling_the_customer(): void
    {
        $impact = app(StoreClosure::class)->impact($this->hq->fresh());
        $this->assertTrue($impact['last_open']);

        $this->page()
            ->assertSee('This is their last open store.')
            ->assertSee('no new renewal is raised while no store is open', false)
            ->assertSee('To end it cleanly, cancel the customer.')
            ->assertSee('Cancel the customer instead');

        // A second store makes neither of them "last".
        $this->branch('Not last');
        $this->assertFalse(app(StoreClosure::class)->impact($this->hq->fresh())['last_open']);
    }

    public function test_closing_the_only_billed_store_says_no_renewal_will_be_raised(): void
    {
        // V4 — another store stays open, but it is excluded from billing.
        $b2 = $this->branch('Unbilled B2');
        $b2->fresh()->update(['is_billable' => false]);
        $bill = app(BillingEngine::class)->raiseTerm($this->hq->account->subscription, ['via' => 'system']);

        $impact = app(StoreClosure::class)->impact($this->hq->fresh());
        $this->assertSame([true, false], [$impact['last_billed'], $impact['last_open']]);

        $this->page()->assertSee('This is the only store they are billed for.')
            ->assertSee('no renewal is raised', false)
            ->assertSee("Their open renewal bill {$bill->number} stays owed until you void it.");
    }

    public function test_a_renewal_being_paid_online_is_said_to_keep_its_amount(): void
    {
        // V7 — E3-B: never re-priced under someone paying it.
        $b2 = $this->branch('Paying B2');
        $bill = app(BillingEngine::class)->raiseTerm($this->hq->account->subscription, ['via' => 'owner']);
        \App\Services\MoneyWrite::during(fn () => $bill->forceFill(['gateway_checkout_at' => now()])->save());

        $this->assertTrue(app(StoreClosure::class)->impact($b2->fresh())['renewal_in_flight']);
        $this->page()->assertSee('is being paid online right now, so it', false)->assertDontSee('will be re-priced to match');
    }

    public function test_when_no_renewal_is_coming_closing_changes_nothing_about_billing(): void
    {
        // V9 — cancelling at the end of the period, then cancelled outright.
        $b2 = $this->branch('Leaving B2');
        app(\App\Services\SubscriptionLifecycle::class)->ownerCancelAtPeriodEnd($this->hq->account->subscription);

        $this->assertTrue(app(StoreClosure::class)->impact($b2->fresh())['no_renewal']);
        $this->page()->assertSee('They cancel at the end of the period, so no renewal is coming')->assertDontSee('From their next renewal');

        app(\App\Services\SubscriptionLifecycle::class)->commit($this->hq->account->fresh()->subscription, 'cancel', ['reason' => 'gone']);
        $this->page()->assertSee('They are cancelled, so no renewal is coming');
    }

    public function test_the_preview_writes_nothing(): void
    {
        $b2 = $this->branch('Quiet B2');
        $before = [\App\Models\BillingCharge::count(), \App\Models\AdminAuditLog::count()];

        app(StoreClosure::class)->impact($b2->fresh());
        $this->page();

        $this->assertSame($before, [\App\Models\BillingCharge::count(), \App\Models\AdminAuditLog::count()]);
    }
}
