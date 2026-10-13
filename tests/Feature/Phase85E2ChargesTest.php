<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\Proration;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * E2.2 — charges: what is owed (11_E2_PLAN.md §3–4).
 *
 * Raising a charge prices it once, locks the amount and changes NOTHING about
 * access (rule 2). One open demand per purpose (E6). A new price supersedes; a
 * mistake is voided with a reason; nothing is ever deleted.
 */
class Phase85E2ChargesTest extends TestCase
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

    private function today(): Carbon
    {
        return Carbon::today(config('billing.timezone'));
    }

    private function engine(): BillingEngine
    {
        return app(BillingEngine::class);
    }

    private function store(string $name, ?Account $account = null): Tenant
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        return app(StoreProvisioner::class)->provision($owner, ['store_name' => $name], $account);
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    /** Active, monthly, paid to $daysAhead from today (a fixture: written directly). */
    private function paying(string $name = 'HQ', int $daysAhead = 20, string $interval = 'monthly'): Tenant
    {
        $hq = $this->store($name);
        $this->sub($hq)->forceFill([
            'status' => 'active', 'interval' => $interval,
            'current_period_start' => $this->today()->addDays($daysAhead)->subMonth(),
            'current_period_end' => $this->today()->addDays($daysAhead),
        ])->save();

        return $hq;
    }

    public function test_a_term_charge_is_priced_by_the_engine_and_changes_nothing_about_access(): void
    {
        $hq = $this->paying();
        $state = fn () => [$this->sub($hq)->status, $this->sub($hq)->current_period_end->toDateString(), $this->sub($hq)->interval];
        $before = $state();

        $charge = $this->engine()->raiseTerm($this->sub($hq));

        $this->assertSame('pending', $charge->status);
        $this->assertSame('renewal', $charge->kind);
        $this->assertEquals(499.0, (float) $charge->amount);
        $this->assertSame($this->today()->addDays(20)->toDateString(), $charge->period_start->toDateString(), 'quoted from the period end');
        $this->assertSame($this->today()->addDays(20)->addMonth()->toDateString(), $charge->period_end->toDateString());
        $this->assertMatchesRegularExpression('/^CHG-\d{4}-0001$/', $charge->number);
        $this->assertSame($before, $state(), 'rule 2: unpaid changes nothing');
        $this->assertSame('active', $this->sub($hq)->accessState());
        $this->assertEquals(499.0, $this->engine()->owed($hq->account_id));
    }

    public function test_a_trialing_account_is_asked_for_its_first_term(): void
    {
        $hq = $this->store('Trial');

        $charge = $this->engine()->raiseTerm($this->sub($hq), ['interval' => 'yearly']);

        $this->assertSame('first_term', $charge->kind);
        $this->assertEquals(4999.0, (float) $charge->amount);
        $this->assertSame('yearly', $charge->interval);
    }

    public function test_the_same_demand_twice_is_one_charge(): void
    {
        $hq = $this->paying();

        $a = $this->engine()->raiseTerm($this->sub($hq));
        $b = $this->engine()->raiseTerm($this->sub($hq));

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, BillingCharge::count());
        $this->assertEquals(499.0, $this->engine()->owed($hq->account_id), 'never owed twice');
    }

    public function test_the_term_key_is_stable_for_a_locked_out_customer(): void
    {
        // Lapsed: the quote moves with today, but the period being renewed does not.
        $hq = $this->paying('Lapsed', -10);

        $a = $this->engine()->raiseTerm($this->sub($hq));
        Carbon::setTestNow(now()->addDay());

        try {
            $b = $this->engine()->raiseTerm($this->sub($hq));
        } finally {
            Carbon::setTestNow();
        }

        // Same price, same period being renewed: the SAME charge (its quoted window is
        // the document; the coverage actually granted is worked out when paid, D-7).
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, BillingCharge::count(), 'one open term charge, not one per day');
        $this->assertSame($b->open_key, BillingEngine::termKey($this->sub($hq)));
    }

    public function test_a_changed_quote_supersedes_the_open_charge(): void
    {
        $hq = $this->paying();
        $monthly = $this->engine()->raiseTerm($this->sub($hq));

        $yearly = $this->engine()->raiseTerm($this->sub($hq), ['interval' => 'yearly']);

        $this->assertNotSame($monthly->id, $yearly->id);
        $this->assertSame('superseded', $monthly->fresh()->status);
        $this->assertSame($yearly->id, $monthly->fresh()->superseded_by);
        $this->assertNull($monthly->fresh()->open_key);
        $this->assertSame(1, BillingCharge::pending()->count());
        $this->assertEquals(4999.0, $this->engine()->owed($hq->account_id), 'owed is the new price, not both');
        $this->assertSame(1, AdminAuditLog::where('action', 'charge.superseded')->count());
    }

    public function test_a_charge_being_collected_online_is_never_replaced(): void
    {
        $hq = $this->paying();
        $charge = $this->engine()->raiseTerm($this->sub($hq));
        \App\Services\MoneyWrite::during(fn () => $charge->forceFill(['gateway_order_id' => 'order_X'])->save());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('being processed online');

        $this->engine()->raiseTerm($this->sub($hq), ['interval' => 'yearly']);
    }

    public function test_paying_ahead_is_one_charge_for_n_cycles_within_limits(): void
    {
        $hq = $this->paying();

        $charge = $this->engine()->raiseTerm($this->sub($hq), ['cycles' => 3]);

        $this->assertEquals(1497.0, (float) $charge->amount);
        $this->assertSame(3, $charge->cycles);
        $this->assertSame($this->today()->addDays(20)->addMonths(3)->toDateString(), $charge->period_end->toDateString());

        foreach ([0, 13] as $bad) {
            try {
                $this->engine()->raiseTerm($this->sub($hq), ['cycles' => $bad]);
                $this->fail("{$bad} cycles must be refused");
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function test_a_void_needs_a_reason_and_keeps_the_row(): void
    {
        $hq = $this->paying();
        $charge = $this->engine()->raiseTerm($this->sub($hq));

        try {
            $this->engine()->void($charge, '  ');
            $this->fail('a void without a reason must be refused');
        } catch (InvalidArgumentException) {
        }

        $this->engine()->void($charge, 'raised in error');

        $fresh = $charge->fresh();
        $this->assertSame('void', $fresh->status);
        $this->assertSame('raised in error', $fresh->void_reason);
        $this->assertSame($this->admin->id, $fresh->voided_by);
        $this->assertNull($fresh->open_key, 'the purpose is free to be raised again');
        $this->assertEquals(0.0, $this->engine()->owed($hq->account_id));
        $this->assertSame(1, BillingCharge::count(), 'never deleted');

        $this->expectException(InvalidArgumentException::class);
        $this->engine()->void($fresh, 'again');
    }

    public function test_a_branch_join_charge_carries_its_working_and_is_owed(): void
    {
        $hq = $this->paying();
        $sub = $this->sub($hq);
        $branch = Tenant::create(['store_name' => 'Branch 2', 'account_id' => $hq->account_id]);
        $proration = app(Proration::class)->forNewBranch($sub);

        $charge = $this->engine()->raiseBranchJoin($sub, $branch, $proration);

        $this->assertSame('branch_join', $charge->kind);
        $this->assertSame($branch->id, $charge->tenant_id);
        $this->assertEquals($proration['amount'], (float) $charge->amount);
        $this->assertNotEmpty($charge->calculation['lines']);
        $this->assertSame('branch_join:' . $branch->id, $charge->open_key);
        $this->assertSame('New branch · Branch 2', $charge->label());

        $again = $this->engine()->raiseBranchJoin($sub, $branch, $proration);
        $this->assertSame($charge->id, $again->id, 'one charge per branch join');
    }

    public function test_charge_numbers_count_up_across_accounts(): void
    {
        $a = $this->engine()->raiseTerm($this->sub($this->paying('A')));
        $b = $this->engine()->raiseTerm($this->sub($this->paying('B')));

        $this->assertSame((int) substr($a->number, -4) + 1, (int) substr($b->number, -4));
    }

    public function test_owed_is_per_account(): void
    {
        $a = $this->paying('A');
        $b = $this->paying('B');
        $this->engine()->raiseTerm($this->sub($a), ['interval' => 'yearly']);

        $this->assertEquals(4999.0, $this->engine()->owed($a->account_id));
        $this->assertEquals(0.0, $this->engine()->owed($b->account_id), "one customer's debt is never another's");
    }
}
