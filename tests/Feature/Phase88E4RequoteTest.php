<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\MoneyWrite;
use App\Services\Settlement;
use App\Services\StoreClosure;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * E4.2 — the open renewal bill stays honest (E4-B), and endings stop chasing it (E4-G).
 */
class Phase88E4RequoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Plan::where('code', 'basic')->update(['monthly_price' => 499, 'yearly_price' => 4999, 'price_tiers' => null]);
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(int $endsInDays = 5): Tenant
    {
        $hq = app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']), ['store_name' => 'Requote HQ']);
        $this->sub($hq)->forceFill(['status' => 'active', 'interval' => 'monthly',
            'current_period_end' => Carbon::today(config('billing.timezone'))->addDays($endsInDays)])->save();

        return $hq;
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    private function renewalBill(Tenant $hq): BillingCharge
    {
        return app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'system']);
    }

    private function open(Tenant $hq): ?BillingCharge
    {
        return BillingCharge::where('account_id', $hq->account_id)->pending()->whereIn('kind', ['first_term', 'renewal'])->first();
    }

    private function branch(Tenant $hq, string $name = 'Requote B2'): Tenant
    {
        return app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']), ['store_name' => $name], $hq->account->fresh());
    }

    public function test_a_branch_joining_closing_or_leaving_billing_reprices_the_open_renewal(): void
    {
        $hq = $this->customer();
        $first = $this->renewalBill($hq);
        $this->assertEquals(499, (float) $first->amount);

        $b2 = $this->branch($hq);
        $this->assertEquals(998, (float) $this->open($hq)->amount, 'two branches from the next renewal');
        $this->assertSame('superseded', $first->fresh()->status);

        app(StoreClosure::class)->close($b2, 'moved out');
        $this->assertEquals(499, (float) $this->open($hq)->amount, 'closed: not billed');

        app(StoreClosure::class)->reopen($b2->fresh(), 'back');
        $this->assertEquals(998, (float) $this->open($hq)->amount);

        $b2->fresh()->update(['is_billable' => false]);
        $this->assertEquals(499, (float) $this->open($hq)->amount, 'excluded from billing');
        $this->assertSame(1, BillingCharge::where('account_id', $hq->account_id)->pending()->whereIn('kind', ['first_term', 'renewal'])->count(), 'one open renewal, always (A-7)');
    }

    public function test_a_list_price_change_reprices_every_open_renewal_but_not_an_agreed_price(): void
    {
        $listed = $this->customer();
        $this->renewalBill($listed);
        $agreed = $this->customer();
        app(SubscriptionLifecycle::class)->commit($this->sub($agreed), 'set_price', ['price' => 399, 'interval' => 'monthly', 'reason' => 'founder']);
        $agreedBill = $this->renewalBill($agreed);

        Plan::where('code', 'basic')->firstOrFail()->update(['monthly_price' => 599]);

        $this->assertEquals(599, (float) $this->open($listed)->amount);
        $this->assertSame($agreedBill->id, $this->open($agreed)->id, 'their price did not change, so neither did their bill');
        $this->assertEquals(399, (float) $this->open($agreed)->amount);
    }

    public function test_an_agreed_price_or_a_period_switch_reprices_at_once(): void
    {
        $hq = $this->customer();
        $this->renewalBill($hq);

        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'set_price', ['price' => 450, 'interval' => 'monthly', 'reason' => 'loyal']);
        $this->assertEquals(450, (float) $this->open($hq)->amount);

        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'clear_price', ['reason' => 'offer over']);
        $this->assertEquals(499, (float) $this->open($hq)->amount);

        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'switch_interval', ['interval' => 'yearly', 'reason' => 'asked']);
        $this->assertSame(['yearly', 4999.0], [$this->open($hq)->interval, (float) $this->open($hq)->amount]);

        app(SubscriptionLifecycle::class)->ownerRequestInterval($this->sub($hq), 'monthly');
        $this->assertSame(['monthly', 499.0], [$this->open($hq)->interval, (float) $this->open($hq)->amount], 'the owner switching back');
    }

    public function test_a_bill_being_paid_online_is_left_alone_until_the_window_passes(): void
    {
        $hq = $this->customer();
        $bill = $this->renewalBill($hq);
        MoneyWrite::during(fn () => $bill->forceFill(['gateway_checkout_at' => now()])->save());

        $this->branch($hq);
        $this->assertSame($bill->id, $this->open($hq)->id, 'never replaced under someone paying it');
        $this->assertEquals(499, (float) $this->open($hq)->amount);

        // The daily run asks directly: it is told "not now", not thrown at.
        $this->assertSame($bill->id, app(BillingEngine::class)->requoteOpenTerm($this->sub($hq))->id);

        Carbon::setTestNow(now()->addMinutes(20));
        app(BillingEngine::class)->requoteOpenTerm($this->sub($hq));
        $this->assertEquals(998, (float) $this->open($hq)->amount, 'the next run catches it');
    }

    public function test_when_the_clock_moves_under_an_open_bill_it_is_re_dated(): void
    {
        $hq = $this->customer();
        $bill = $this->renewalBill($hq);
        $oldStart = $bill->period_start->toDateString();

        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'extend', ['days' => 5, 'reason' => 'goodwill']);
        app(BillingEngine::class)->requoteOpenTerm($this->sub($hq));

        $open = $this->open($hq);
        $this->assertNotSame($bill->id, $open->id);
        $this->assertSame($this->sub($hq)->current_period_end->toDateString(), $open->period_start->toDateString(),
            'the bill starts where their cover now ends, not where it used to (' . $oldStart . ')');
        $this->assertEquals(499, (float) $open->amount);
    }

    public function test_a_reopened_bill_is_debt_and_is_never_repriced(): void
    {
        $hq = $this->customer();
        $row = app(Settlement::class)->payTerm($this->sub($hq), 'renew', ['method' => 'cash']);
        app(Settlement::class)->reverse($row, 'cheque bounced');
        $reopened = BillingCharge::findOrFail($row->charge_id);
        $this->assertNotNull($reopened->fresh()->reopened_at);

        $this->branch($hq);

        $this->assertSame('pending', $reopened->fresh()->status);
        $this->assertEquals(499, (float) $reopened->fresh()->amount);
        $this->assertSame(1, BillingCharge::where('account_id', $hq->account_id)->pending()->whereIn('kind', ['first_term', 'renewal'])->count(),
            'and no fresh renewal bill appears beside it just because something changed');
    }

    public function test_paying_late_is_not_a_reason_to_reprice(): void
    {
        $hq = $this->customer(endsInDays: 1);
        $bill = $this->renewalBill($hq);

        Carbon::setTestNow(now()->addDays(4));                 // in grace now: the quote's dates moved
        app(BillingEngine::class)->requoteOpenTerm($this->sub($hq));

        $this->assertSame($bill->id, $this->open($hq)->id, 'same amount, same bill — re-dated only when they pay (A-7)');
    }

    public function test_a_failed_reprice_never_fails_the_change_that_caused_it(): void
    {
        $hq = $this->customer();
        $this->renewalBill($hq);
        $b2 = $this->branch($hq);
        $this->app->instance(BillingEngine::class, new class extends BillingEngine {
            public function __construct() {}
            public function requoteOpenTerm(Subscription $subscription, array $options = []): ?BillingCharge
            {
                throw new \RuntimeException('simulated');
            }
        });
        Log::spy();

        app(StoreClosure::class)->close($b2->fresh(), 'moved out');

        $this->assertTrue($b2->fresh()->isClosed(), 'the closure stands');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'daily run will retry'))->once();
    }

    public function test_a_cancel_at_period_end_stops_chasing_the_renewal_but_not_branch_debt(): void
    {
        $hq = $this->customer();
        $renewal = $this->renewalBill($hq);
        $this->branch($hq);
        $join = BillingCharge::where('account_id', $hq->account_id)->where('kind', 'branch_join')->sole();

        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'cancel', ['at_period_end' => true, 'reason' => 'closing the shop']);

        $this->assertNull($this->open($hq), 'no renewal is coming');
        $this->assertSame('pending', $join->fresh()->status, 'the branch already used its part period');
        $this->assertContains($renewal->fresh()->status, ['void', 'superseded']);
    }

    public function test_the_owner_cancelling_voids_the_renewal_too(): void
    {
        $hq = $this->customer();
        $this->renewalBill($hq);

        app(SubscriptionLifecycle::class)->ownerCancelAtPeriodEnd($this->sub($hq));

        $this->assertNull($this->open($hq));
    }

    public function test_churn_and_trial_expiry_void_what_they_never_paid_as_the_system(): void
    {
        $hq = $this->customer(endsInDays: 1);
        $this->renewalBill($hq);
        Carbon::setTestNow(now()->addDays(45));
        $sub = $this->sub($hq);
        app(SubscriptionLifecycle::class)->lapse($sub);
        $this->assertNotNull($this->open($hq), 'lapsed but not churned: they still owe it to come back');

        app(SubscriptionLifecycle::class)->lapseToChurn($this->sub($hq), 7, 30);

        $this->assertNull($this->open($hq));
        $this->assertSame('system', AdminAuditLog::where('action', 'charge.voided')->latest('id')->first()->meta['actor']);

        Carbon::setTestNow();
        $trial = app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']), ['store_name' => 'Trial']);
        app(BillingEngine::class)->raiseTerm($this->sub($trial), ['via' => 'owner']);   // opened Pay, never paid
        $this->sub($trial)->forceFill(['current_period_end' => Carbon::today(config('billing.timezone'))->subDay()])->save();

        $this->assertTrue(app(SubscriptionLifecycle::class)->expireTrial($this->sub($trial)));
        $this->assertNull($this->open($trial));
    }
}
