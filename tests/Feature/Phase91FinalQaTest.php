<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UnappliedPayment;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use App\Support\RenewalNudge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The release's final manual pass (22_RELEASE_ACCEPTANCE, 2026-10-23) — what a person found
 * that the suites did not: a banner sentence with no amount in it, a "Wrong customer?" list
 * with nobody in it, and audit entries that never showed why.
 */
class Phase91FinalQaTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Plan::where('code', 'basic')->update(['monthly_price' => 499, 'yearly_price' => 4999, 'price_tiers' => null]);
        \Tests\Fakes\FakeRazorpay::install($this->app);
        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00', config('billing.timezone')));
        $this->actingAs($this->operator = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Tenant, 1: User} */
    private function customer(string $name, string $ends = '2026-11-06'): array
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin', 'name' => $name . ' Owner']);
        $hq = app(StoreProvisioner::class)->provision($owner, ['store_name' => $name]);
        SubscriptionLifecycle::writing(fn () => $this->sub($hq)->forceFill(['status' => 'active', 'interval' => 'monthly', 'current_period_end' => $ends])->save());

        return [$hq, $owner->fresh()];
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    private function money(?Tenant $of, string $id): UnappliedPayment
    {
        return app(Settlement::class)->recordUnapplied(['id' => $id, 'order_id' => null, 'amount' => 49900, 'currency' => 'INR',
            'status' => 'captured', 'created_at' => now()->timestamp], null, $of?->account_id, 'test', 'order.paid');
    }

    public function test_a_renewal_you_voided_is_announced_without_an_empty_amount(): void
    {
        [$hq, $owner] = $this->customer('Voided Optical');
        $bill = app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'system']);
        app(BillingEngine::class)->void($bill, 'they pay by cheque on the day');

        $this->actingAs($owner)->get(route('tenant.dashboard'))->assertOk()
            ->assertSee('Your plan is up for renewal on 06 Nov 2026.')
            ->assertDontSee('Your renewal of')
            ->assertDontSee('Pay now');
    }

    public function test_a_free_period_ending_later_this_week_is_announced_without_an_amount(): void
    {
        [$hq, $owner] = $this->customer('Comped Optical', '2026-10-06');
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', config('billing.timezone')));
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'comp', ['months' => 1, 'reason' => 'launch offer']);   // → 6 Nov
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00', config('billing.timezone')));

        $this->actingAs($owner)->get(route('tenant.dashboard'))->assertOk()
            ->assertSee('Your plan is up for renewal on 06 Nov 2026.')
            ->assertDontSee('Your renewal of');
    }

    public function test_a_fault_pricing_the_banner_costs_the_banner_never_the_page(): void
    {
        [, $owner] = $this->customer('Fault Optical');   // due in 5 days, no bill yet: the banner prices it
        $this->app->instance(\App\Services\PriceResolver::class, new class extends \App\Services\PriceResolver {
            public function __construct() {}
            public function renewalPrice(Subscription $subscription, ?string $interval = null): float
            {
                throw new \App\Services\PlanMissing('simulated: no plan row');
            }
        });
        \Illuminate\Support\Facades\Exceptions::fake();

        $this->actingAs($owner)->get(route('tenant.dashboard'))->assertOk()->assertDontSee('Your renewal of');
        \Illuminate\Support\Facades\Exceptions::assertReported(\App\Services\PlanMissing::class);   // and someone is told
    }

    public function test_money_on_the_wrong_customer_can_be_moved_when_no_unknown_money_is_open(): void
    {
        [$wrong] = $this->customer('Wrong Optical');
        [$right] = $this->customer('Right Optical');
        $this->money($wrong, 'pay_ONLY_MATCHED');
        $this->assertSame(0, UnappliedPayment::open()->whereNull('account_id')->count(), 'nothing unknown: only matched money is open');

        $html = $this->withSession(['auth.password_confirmed_at' => time()])->get(route('superadmin.billing.index'))->assertOk()->getContent();

        preg_match('#id="rm-acc-[^"]+".*?</select>#s', $html, $select);
        $this->assertNotEmpty($select, 'the Wrong customer? dialog is on the page');
        $this->assertStringContainsString($right->account_id, $select[0], 'the customer it belongs to can be chosen');
        $this->assertStringNotContainsString('value="' . $wrong->account_id . '"', $select[0], 'not the one it is already on');
    }

    public function test_the_whatsapp_nudge_reads_right_without_an_amount(): void
    {
        [$hq] = $this->customer('Nudge Optical');

        $this->assertStringContainsString('Your renewal is due on 6 Nov 2026',
            RenewalNudge::message($hq->account, null, Carbon::parse('2026-11-06'), false));
        $this->assertStringContainsString('Your renewal of ₹499.00 is due on 6 Nov 2026',
            RenewalNudge::message($hq->account, 499.0, Carbon::parse('2026-11-06'), false));
    }

    public function test_the_audit_shows_why_an_action_was_taken(): void
    {
        [$hq] = $this->customer('Audit Optical');
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'extend', ['days' => 14, 'reason' => 'setup delay on our side']);

        $this->get(route('superadmin.audit.index'))->assertOk()->assertSee('“setup delay on our side”', false);
        $this->get(route('superadmin.accounts.show', $hq->account_id))->assertOk()->assertSee('“setup delay on our side”', false);
    }
}
