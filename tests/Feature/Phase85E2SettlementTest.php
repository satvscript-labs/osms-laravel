<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\PaymentRecorder;
use App\Services\Proration;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * E2.3 — Settlement: what was received, against what was owed (11_E2_PLAN.md §7).
 *
 * Asserts what the customer can do (access, covered-until), what they owe and
 * what was collected (rule 7) — and that one payment never buys twice (E2-G).
 */
class Phase85E2SettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->admin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function today(): Carbon
    {
        return Carbon::today(config('billing.timezone'));
    }

    private function engine(): BillingEngine
    {
        return app(BillingEngine::class);
    }

    private function settlement(): Settlement
    {
        return app(Settlement::class);
    }

    private function store(string $name, $account = null): Tenant
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        return app(StoreProvisioner::class)->provision($owner, ['store_name' => $name], $account);
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    private function paying(string $name = 'HQ', int $daysAhead = 20, string $interval = 'monthly'): Tenant
    {
        $hq = $this->store($name);
        $this->sub($hq)->forceFill([
            'status' => 'active', 'interval' => $interval,
            'current_period_end' => $this->today()->addDays($daysAhead),
        ])->save();

        return $hq;
    }

    private function collected(Tenant $hq): float
    {
        return (float) SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $hq->account_id)
            ->whereNull('reversed_at')->where('method', '!=', 'comp')->sum('amount');
    }

    private function endOf(Tenant $hq): string
    {
        return $this->sub($hq)->current_period_end->toDateString();
    }

    // ------------------------------------------------------------------
    // A term, paid
    // ------------------------------------------------------------------

    public function test_paying_a_term_records_what_it_paid_for_and_moves_the_clock_once(): void
    {
        $hq = $this->paying();
        $charge = $this->engine()->raiseTerm($this->sub($hq));

        $row = $this->settlement()->settle($charge, ['method' => 'cash']);

        $end = $this->today()->addDays(20)->addMonth()->toDateString();
        $this->assertSame($charge->id, $row->charge_id, 'rule 6: the payment says what it paid for');
        $this->assertEquals(499.0, (float) $row->amount);
        $this->assertSame($this->today()->addDays(20)->toDateString(), $row->granted_start->toDateString(), 'early: from the period end, no lost days');
        $this->assertSame($end, $row->granted_end->toDateString());
        $this->assertSame($end, $this->endOf($hq));
        $this->assertSame('active', $this->sub($hq)->accessState());
        $this->assertSame('paid', $charge->fresh()->status);
        $this->assertNull($charge->fresh()->open_key);
        $this->assertSame($end, $charge->fresh()->granted_end->toDateString());
        $this->assertEquals(0.0, $this->engine()->owed($hq->account_id));
        $this->assertEquals(499.0, $this->collected($hq));
        $this->assertSame(1, AdminAuditLog::where('action', 'payment.recorded')->count());
        $this->assertSame(1, AdminAuditLog::where('action', 'subscription.renew')->count());
    }

    public function test_a_late_payment_covers_from_the_day_it_was_recorded_not_the_quote(): void
    {
        // D-7 — locked out since 10 days ago; the bill was raised 2 days before they paid.
        $hq = $this->paying('Late', -10);
        Carbon::setTestNow(now()->subDays(2));
        $charge = $this->engine()->raiseTerm($this->sub($hq));
        Carbon::setTestNow();

        $row = $this->settlement()->settle($charge, ['method' => 'upi']);

        $this->assertSame($this->today()->subDays(2)->toDateString(), $charge->fresh()->period_start->toDateString(), 'the charge keeps its quote');
        $this->assertSame($this->today()->toDateString(), $row->granted_start->toDateString(), 'coverage from the day it was recorded');
        $this->assertSame($this->today()->addMonth()->toDateString(), $this->endOf($hq));
        $this->assertSame('active', $this->sub($hq)->accessState());
    }

    public function test_paying_three_months_ahead_covers_three_months(): void
    {
        $hq = $this->paying();
        $charge = $this->engine()->raiseTerm($this->sub($hq), ['cycles' => 3]);

        $this->settlement()->settle($charge, ['method' => 'bank_transfer']);

        $this->assertSame($this->today()->addDays(20)->addMonths(3)->toDateString(), $this->endOf($hq));
        $this->assertEquals(1497.0, $this->collected($hq));
    }

    public function test_a_pending_switch_lands_with_the_term_it_pays(): void
    {
        $hq = $this->paying();
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'switch_interval', ['interval' => 'yearly']);

        $charge = $this->engine()->raiseTerm($this->sub($hq));
        $this->assertEquals(4999.0, (float) $charge->amount, 'priced at the period it will run');

        $this->settlement()->settle($charge, ['method' => 'cash']);

        $this->assertSame('yearly', $this->sub($hq)->interval);
        $this->assertNull($this->sub($hq)->pending_interval);
        $this->assertSame($this->today()->addDays(20)->addYear()->toDateString(), $this->endOf($hq));
    }

    // ------------------------------------------------------------------
    // The amount
    // ------------------------------------------------------------------

    public function test_less_than_the_bill_is_a_recorded_discount_against_the_bill(): void
    {
        $hq = $this->paying();
        $charge = $this->engine()->raiseTerm($this->sub($hq));

        $row = $this->settlement()->settle($charge, ['amount' => 450, 'method' => 'cash', 'discount_reason' => 'bargained']);

        $this->assertEquals(450.0, (float) $row->amount);
        $this->assertEquals(499.0, (float) $row->list_amount);
        $this->assertEquals(49.0, (float) $row->discount_amount);
        $this->assertSame('bargained', $row->discount_reason);
        $this->assertEquals(0.0, $this->engine()->owed($hq->account_id), 'a bargain settles the bill');
    }

    public function test_more_than_the_bill_is_refused_unless_confirmed_and_nothing_moves(): void
    {
        $hq = $this->paying();
        $charge = $this->engine()->raiseTerm($this->sub($hq));
        $end = $this->endOf($hq);

        try {
            $this->settlement()->settle($charge, ['amount' => 4990, 'method' => 'cash']);
            $this->fail('D-4: more than the bill must be confirmed');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('MORE than the bill', $e->getMessage());
            $this->assertStringContainsString('10×', $e->getMessage());
        }

        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count(), 'nothing recorded');
        $this->assertSame('pending', $charge->fresh()->status);
        $this->assertSame($end, $this->endOf($hq), 'nothing granted');

        $row = $this->settlement()->settle($charge, ['amount' => 4990, 'method' => 'cash', 'confirm_over' => '1']);

        $this->assertEquals(4990.0, (float) $row->amount, 'confirmed: recorded as received');
        $this->assertEquals(4491.0, AdminAuditLog::where('action', 'payment.recorded')->first()->meta['over_the_bill']);
        $this->assertSame($this->today()->addDays(20)->addMonth()->toDateString(), $this->endOf($hq), 'still ONE cycle — more time is chosen with months, not by overpaying');
    }

    public function test_a_payment_needs_an_amount_and_cannot_be_dated_in_the_future(): void
    {
        $hq = $this->paying();
        $charge = $this->engine()->raiseTerm($this->sub($hq));

        foreach ([['amount' => 0], ['paid_at' => $this->today()->addDay()->toDateString()]] as $bad) {
            try {
                $this->settlement()->settle($charge, $bad + ['method' => 'cash']);
                $this->fail('must be refused: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());

        $row = $this->settlement()->settle($charge, ['method' => 'cash', 'paid_at' => $this->today()->subDays(3)->toDateString()]);
        $this->assertSame($this->today()->subDays(3)->toDateString(), $row->paid_at->toDateString(), 'money dated when received');
        $this->assertSame($this->today()->addDays(20)->toDateString(), $row->granted_start->toDateString(), 'coverage per E2-C, not back-dated');
    }

    public function test_a_charge_that_is_no_longer_owed_cannot_be_paid(): void
    {
        $hq = $this->paying();
        $paid = $this->engine()->raiseTerm($this->sub($hq));
        $this->settlement()->settle($paid, ['method' => 'cash']);

        $void = $this->engine()->raiseTerm($this->sub($hq));
        $this->engine()->void($void, 'error');

        foreach ([$paid, $void] as $charge) {
            try {
                $this->settlement()->settle($charge->fresh(), ['method' => 'cash']);
                $this->fail("{$charge->number} must not be payable");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('nothing was recorded', $e->getMessage());
            }
        }

        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()->count(), 'one payment, for the one paid bill');
    }

    // ------------------------------------------------------------------
    // Waive
    // ------------------------------------------------------------------

    public function test_waiving_a_term_gives_the_time_away_visibly(): void
    {
        $hq = $this->paying('Waived', -3);
        $charge = $this->engine()->raiseTerm($this->sub($hq));

        try {
            $this->settlement()->waive($charge, '');
            $this->fail('a waiver needs a reason');
        } catch (InvalidArgumentException) {
        }

        $row = $this->settlement()->waive($charge, 'goodwill');

        $this->assertSame('comp', $row->method);
        $this->assertEquals(0.0, (float) $row->amount);
        $this->assertSame($charge->id, $row->charge_id);
        $this->assertSame('comp', $this->sub($hq)->override_kind);
        $this->assertSame('active', $this->sub($hq)->accessState());
        $this->assertEquals(0.0, $this->engine()->owed($hq->account_id));
        $this->assertEquals(0.0, $this->collected($hq), 'given away, not collected');
    }

    // ------------------------------------------------------------------
    // Reverse → reopen → pay again (FB-20, E2-G)
    // ------------------------------------------------------------------

    public function test_a_reversal_reopens_the_bill_and_paying_it_again_never_grants_twice(): void
    {
        $hq = $this->paying();
        $charge = $this->engine()->raiseTerm($this->sub($hq));
        $mistyped = $this->settlement()->settle($charge, ['amount' => 4990, 'method' => 'cash', 'confirm_over' => 1]);
        $covered = $this->endOf($hq);

        $this->settlement()->reverse($mistyped, 'typed an extra zero');

        $this->assertSame('pending', $charge->fresh()->status, 'FB-20: the debt is visible again');
        $this->assertNotNull($charge->fresh()->reopened_at);
        $this->assertSame('Owed (reopened)', $charge->fresh()->statusLabel());
        $this->assertEquals(499.0, $this->engine()->owed($hq->account_id));
        $this->assertSame($covered, $this->endOf($hq), 'access untouched by a reversal');
        $this->assertEquals(0.0, $this->collected($hq));

        $right = $this->settlement()->settle($charge->fresh(), ['amount' => 499, 'method' => 'cash']);

        $this->assertSame($covered, $this->endOf($hq), 'E2-G: the cycle was already granted — not a second time');
        $this->assertNull($right->granted_end, 'this payment granted nothing new');
        $this->assertSame($covered, $right->period_end->toDateString(), 'it says which cover it paid for');
        $this->assertSame('paid', $charge->fresh()->status);
        $this->assertEquals(499.0, $this->collected($hq));
        $this->assertEquals(0.0, $this->engine()->owed($hq->account_id));
        $this->assertSame(1, AdminAuditLog::where('action', 'charge.reopened')->count());
    }

    public function test_reversing_a_row_with_no_charge_reopens_nothing(): void
    {
        $hq = $this->paying();
        $legacy = app(PaymentRecorder::class)->record($this->sub($hq), 499, 'cash');   // pre-engine shape

        $this->settlement()->reverse($legacy, 'duplicate');

        $this->assertNotNull($legacy->fresh()->reversed_at);
        $this->assertSame(0, BillingCharge::count());
        $this->assertSame(0, AdminAuditLog::where('action', 'charge.reopened')->count());
    }

    // ------------------------------------------------------------------
    // A branch's part period, paid
    // ------------------------------------------------------------------

    public function test_paying_a_branch_charge_moves_no_clock_and_keeps_its_working_on_the_receipt(): void
    {
        $hq = $this->paying();
        $sub = $this->sub($hq);
        $branch = Tenant::create(['store_name' => 'Branch 2', 'account_id' => $hq->account_id]);
        $charge = $this->engine()->raiseBranchJoin($sub, $branch, app(Proration::class)->forNewBranch($sub));
        $end = $this->endOf($hq);

        $row = $this->settlement()->settle($charge, ['method' => 'cash']);

        $this->assertSame($end, $this->endOf($hq), 'a branch charge buys no time');
        $this->assertSame($branch->id, $row->tenant_id, 'what did Branch 2 cost? answerable');
        $this->assertTrue($row->hasCalculation(), 'the receipt shows the working');
        $this->assertNull($row->granted_end);
        $this->assertEquals(0.0, $this->engine()->owed($hq->account_id));
    }

    // ------------------------------------------------------------------
    // The old levers, now through the engine
    // ------------------------------------------------------------------

    public function test_renew_now_is_raise_then_settle_and_its_payment_names_its_charge(): void
    {
        $hq = $this->paying();

        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'renew', ['method' => 'cash']);

        $row = SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $hq->account_id)->sole();
        $this->assertNotNull($row->charge_id);
        $this->assertSame('paid', BillingCharge::find($row->charge_id)->status);
        $this->assertSame($this->today()->addDays(20)->addMonth()->toDateString(), $this->endOf($hq));
    }

    public function test_previewing_a_renewal_leaves_no_charge_behind(): void
    {
        $hq = $this->paying();

        $preview = app(SubscriptionLifecycle::class)->preview($this->sub($hq), 'renew', ['method' => 'cash']);

        $this->assertNull($preview['error']);
        $this->assertArrayHasKey('current_period_end', $preview['changes']);
        $this->assertSame(1, $preview['ledger_rows_added']);
        $this->assertSame(0, BillingCharge::count(), 'a preview is a commit, undone — charge included');
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());
    }
}
