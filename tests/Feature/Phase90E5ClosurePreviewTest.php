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
            ->assertSee('keeps running — and keeps being billed — until you cancel the customer', false)
            ->assertSee('Cancel the customer instead');

        // A second store makes neither of them "last".
        $this->branch('Not last');
        $this->assertFalse(app(StoreClosure::class)->impact($this->hq->fresh())['last_open']);
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
