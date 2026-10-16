<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\UnappliedPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Scenarios\BillingScenarioSeeder;
use Tests\TestCase;

/**
 * The local billing test data builds every scenario through the engine, says what
 * each one is, and can be run twice without duplicating anything.
 */
class Phase89ScenarioSeederTest extends TestCase
{
    use RefreshDatabase;

    private function sub(string $scenario): Subscription
    {
        return Subscription::withoutGlobalScopes()
            ->where('account_id', Account::where('display_name', $scenario)->firstOrFail()->id)->firstOrFail();
    }

    private function owed(string $scenario)
    {
        return BillingCharge::where('account_id', Account::where('display_name', $scenario)->value('id'))->pending();
    }

    public function test_every_scenario_is_built_and_named_for_what_it_tests(): void
    {
        Artisan::call('db:seed', ['--class' => BillingScenarioSeeder::class]);

        $names = Account::where('display_name', 'like', 'E%test')->pluck('display_name');
        $this->assertCount(29, $names);   // E1 7 · E2 5 · E3 4 (+ one payment with no customer) · E4 9 · E5 4
        $this->assertTrue($names->every(fn ($n) => preg_match('/^E[1-5]_[a-z0-9_]+_test$/', $n) === 1));

        $this->assertSame('canceled', $this->sub('E1_trial_ended_test')->status);
        $this->assertTrue($this->sub('E1_suspended_by_operator_test')->isSuspended());
        $this->assertSame('yearly', $this->sub('E1_pending_switch_to_yearly_test')->pending_interval);
        $this->assertSame(1, $this->owed('E2_branch_join_bill_owed_test')->where('kind', 'branch_join')->count());
        $this->assertNotNull($this->owed('E2_reversed_payment_reopened_test')->value('reopened_at'));
        $this->assertSame('past_due', $this->sub('E2_lapsed_past_due_test')->status);
        $this->assertNull($this->owed('E3_reversed_online_payment_test')->value('gateway_order_id'), 'F1 — no stale order');
        $this->assertSame(3, UnappliedPayment::open()->whereNotNull('account_id')->count(), 'known customer + amount mismatch + wrong customer');
        $this->assertSame(1, UnappliedPayment::open()->whereNull('account_id')->count());
        $this->assertSame(1, $this->owed('E4_renewal_due_in_7_days_test')->where('raised_via', 'system')->count(), 'the morning run raised it');
        $this->assertSame(0, $this->owed('E4_free_period_ends_tomorrow_test')->count(), 'never billed ahead');
        $this->assertSame(0, $this->owed('E4_renewal_voided_by_operator_test')->count(), 'your void stands');
        $this->assertSame(0, $this->owed('E4_cancels_at_period_end_test')->count());
        $this->assertSame(31, $this->sub('E4_month_end_31st_test')->current_period_end->day);

        // E5
        $branch = \App\Models\Tenant::where('store_name', 'E5_close_one_of_two_branches_test — Branch 2')->firstOrFail();
        $impact = app(\App\Services\StoreClosure::class)->impact($branch);
        $this->assertSame([998.0, 499.0], [$impact['before']['effective'], $impact['after']['effective']]);
        $this->assertNotNull($impact['open_renewal']);
        $last = \App\Models\Tenant::where('store_name', 'E5_close_last_store_test')->firstOrFail();
        $this->assertTrue(app(\App\Services\StoreClosure::class)->impact($last)['last_open']);
        $this->assertSame('trialing', $this->sub('E5_trial_ends_in_3_days_test')->status);
        $this->assertSame(3, $this->sub('E5_trial_ends_in_3_days_test')->trialDaysLeft());
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        Artisan::call('db:seed', ['--class' => BillingScenarioSeeder::class]);
        $counts = fn () => [Account::count(), BillingCharge::count(), UnappliedPayment::count(), \App\Models\SubscriptionInvoice::withoutGlobalScopes()->count()];
        $first = $counts();

        Artisan::call('db:seed', ['--class' => BillingScenarioSeeder::class]);

        $this->assertSame($first, $counts());
    }

    public function test_it_refuses_to_run_outside_local(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        (new BillingScenarioSeeder())->run();
    }
}
