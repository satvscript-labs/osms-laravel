<?php

namespace Tests\Feature;

use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingCycle;
use App\Services\Proration;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * FB-32 — month-end dates are clamped to the account's anchor day: a customer who
 * renews on the 31st renews on 28 Feb, then on 31 Mar again — never 3 Mar
 * (overflow), never 28 Mar for ever after (drift).
 */
class Phase87MonthEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function on(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date . ' 12:00:00', config('billing.timezone')));
    }

    private function customer(string $endsOn, string $interval = 'monthly'): Tenant
    {
        $hq = app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']), ['store_name' => 'Month End']);
        $this->sub($hq)->forceFill(['status' => 'active', 'interval' => $interval, 'current_period_end' => $endsOn])->save();

        return $hq;
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    /** Record their next renewal; returns [covered until, the bill's own period end]. */
    private function renew(Tenant $hq, int $cycles = 1): array
    {
        $row = app(Settlement::class)->payTerm($this->sub($hq), 'renew', ['method' => 'cash', 'cycles' => $cycles]);

        return [$this->sub($hq)->current_period_end->toDateString(), BillingCharge::findOrFail($row->charge_id)->period_end->toDateString()];
    }

    private function d(string $date): Carbon
    {
        return Carbon::parse($date, config('billing.timezone'));
    }

    public function test_the_arithmetic_clamps_and_comes_back_to_the_anchor(): void
    {
        $add = fn (string $from, int $n, ?int $anchor = null, string $interval = 'monthly') => BillingCycle::add($this->d($from), $interval, $n, $anchor)->toDateString();

        $this->assertSame('2027-02-28', $add('2027-01-31', 1), 'clamped, not 3 Mar');
        $this->assertSame('2027-03-31', $add('2027-01-31', 2), 'from one start: back on the 31st');
        $this->assertSame('2027-04-30', $add('2027-01-31', 3));
        $this->assertSame('2028-02-29', $add('2028-01-31', 1), 'leap year');
        $this->assertSame('2027-03-31', $add('2027-02-28', 1, 31), 'chained with the anchor = from the start');
        $this->assertSame('2027-03-28', $add('2027-02-28', 1), 'no anchor: the 28th is the day');
        $this->assertSame('2027-03-30', $add('2027-01-30', 2));
        $this->assertSame('2027-02-15', $add('2027-01-15', 1), 'ordinary days untouched');
        $this->assertSame('2027-02-28', $add('2027-03-31', -1), 'one cycle back clamps too');
        $this->assertSame('2029-02-28', $add('2028-02-29', 1, null, 'yearly'), 'leap day, yearly');
        $this->assertSame('2032-02-29', $add('2029-02-28', 3, 29, 'yearly'), 'and back on it in the next leap year');
    }

    public function test_the_anchor_counts_only_while_the_date_sits_on_it(): void
    {
        $this->assertSame(31, BillingCycle::anchorFor(31, $this->d('2027-02-28')));
        $this->assertSame(31, BillingCycle::anchorFor(31, $this->d('2027-04-30')));
        $this->assertSame(30, BillingCycle::anchorFor(31, $this->d('2027-03-30')), '30 Mar is not where a 31 lands');
        $this->assertSame(5, BillingCycle::anchorFor(31, $this->d('2027-03-05')), 'moved off it (extension, restart)');
        $this->assertSame(28, BillingCycle::anchorFor(null, $this->d('2027-02-28')), 'unknown: the date itself');
        $this->assertSame(29, BillingCycle::anchorFor(29, $this->d('2027-02-28')));
    }

    public function test_a_customer_on_the_31st_renews_on_each_months_last_day_and_never_drifts(): void
    {
        $hq = $this->customer('2027-01-31');
        $seen = [];

        foreach (['2027-01-25', '2027-02-25', '2027-03-25', '2027-04-25', '2027-05-25'] as $today) {
            $this->on($today);
            [$until, $billEnd] = $this->renew($hq);
            $this->assertSame($until, $billEnd, 'the bill quoted the period the clock then granted');
            $seen[] = $until;
        }

        $this->assertSame(['2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31', '2027-06-30'], $seen);
        $this->assertSame(31, $this->sub($hq)->billing_anchor_day);
    }

    public function test_paying_months_ahead_lands_on_the_anchor(): void
    {
        $hq = $this->customer('2027-01-31');
        $this->on('2027-01-20');

        $this->assertSame(['2027-04-30', '2027-04-30'], $this->renew($hq, 3));
    }

    public function test_a_late_payer_restarts_from_today_and_today_becomes_the_day(): void
    {
        $hq = $this->customer('2027-01-31');
        $this->on('2027-01-25');
        $this->renew($hq);                                   // → 28 Feb, anchor 31

        $this->on('2027-03-05');                             // lapsed; pays from today (D-7)
        $this->assertSame(['2027-04-05', '2027-04-05'], $this->renew($hq));
        $this->assertSame(5, $this->sub($hq)->billing_anchor_day);
    }

    public function test_an_extension_by_days_moves_the_day_for_good(): void
    {
        $hq = $this->customer('2027-01-31');
        $this->on('2027-01-25');
        $this->renew($hq);                                   // → 28 Feb, anchor 31
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'extend', ['days' => 5, 'reason' => 'goodwill']);
        $this->assertSame('2027-03-05', $this->sub($hq)->current_period_end->toDateString());

        $this->assertSame(['2027-04-05', '2027-04-05'], $this->renew($hq), 'not 31 Mar: the operator moved the day');
    }

    public function test_free_months_clamp_too(): void
    {
        $hq = $this->customer('2027-01-31');
        $this->on('2027-01-25');

        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'comp', ['months' => 1, 'reason' => 'launch offer']);
        $this->assertSame('2027-02-28', $this->sub($hq)->current_period_end->toDateString(), 'not 3 Mar');

        $this->on('2027-02-20');
        $this->assertSame(['2027-03-31', '2027-03-31'], $this->renew($hq), 'and the next renewal is back on the 31st');
    }

    public function test_a_yearly_leap_day_customer_keeps_29_february(): void
    {
        $hq = $this->customer('2028-02-29', 'yearly');
        $this->on('2028-02-20');
        $this->assertSame(['2029-02-28', '2029-02-28'], $this->renew($hq));

        $this->on('2029-02-20');
        $this->assertSame(['2032-02-29', '2032-02-29'], $this->renew($hq, 3));
    }

    public function test_a_branch_joining_in_a_clamped_period_is_charged_on_its_real_length(): void
    {
        $hq = $this->customer('2027-01-31');
        $this->on('2027-01-25');
        $this->renew($hq);                                   // 31 Jan → 28 Feb
        $this->on('2027-02-25');
        $this->renew($hq);                                   // 28 Feb → 31 Mar

        $this->on('2027-03-16');
        $quote = app(Proration::class)->forNewBranch($this->sub($hq), Carbon::today(config('billing.timezone')));

        $this->assertTrue($quote['applicable']);
        $this->assertSame('2027-03-31', $quote['period_end']->toDateString());
        $last = end($quote['segments']);
        $this->assertSame(['2027-02-28', '2027-03-31', 31], [$last['cycle_start']->toDateString(), $last['cycle_end']->toDateString(), $last['cycle_days']],
            'the cycle the branch joins is 28 Feb → 31 Mar (31 days), as the clock granted it');
        $this->assertSame(15, $last['days']);
    }

    public function test_no_billing_code_adds_months_or_years_except_billing_cycle(): void
    {
        // FB-32 — a stray addMonths() would overflow (31 Jan → 3 Mar) and disagree with the clock.
        $offenders = [];
        foreach (glob(app_path('{Services,Http/Controllers,Console/Commands,Models}/*.php'), GLOB_BRACE) ?: [] as $file) {
            if (str_ends_with($file, 'BillingCycle.php') || str_ends_with($file, 'Customer.php')) {
                continue;   // Customer: birthdays, not billing
            }
            if (preg_match('/->add(Months?|Years?)(NoOverflow)?\(/', (string) file_get_contents($file))) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'use BillingCycle::add() for any billing date');
    }
}
