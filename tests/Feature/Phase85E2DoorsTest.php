<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E2.4 — every door now goes through charges (11_E2_PLAN.md §5, §7 edges 3, 19–26).
 *
 * The branch join is owed, not "paid" (FB-18); a payment says what it pays for
 * (FB-08); endings and grants close what is open (E2-E / E2-F); every preview
 * shows owed moving; nothing crosses accounts.
 */
class Phase85E2DoorsTest extends TestCase
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

    private function store(string $name, ?Account $account = null): Tenant
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        return app(StoreProvisioner::class)->provision($owner, ['store_name' => $name], $account);
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    private function paying(string $name = 'HQ', int $daysAhead = 20): Tenant
    {
        $hq = $this->store($name);
        $this->sub($hq)->forceFill([
            'status' => 'active', 'interval' => 'monthly',
            'current_period_start' => $this->today()->addDays($daysAhead)->subMonth(),
            'current_period_end' => $this->today()->addDays($daysAhead),
        ])->save();

        return $hq;
    }

    /** A paying account with a second branch — so one open branch_join charge. */
    private function withBranch(string $name = 'HQ'): array
    {
        $hq = $this->paying($name);
        $branch = $this->store("{$name} — Branch 2", $hq->account->fresh());

        return [$hq, $branch, BillingCharge::where('account_id', $hq->account_id)->where('kind', 'branch_join')->sole()];
    }

    private function owed(Tenant $hq): float
    {
        return app(BillingEngine::class)->owed($hq->account_id);
    }

    private function asOperator(): self
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    // ------------------------------------------------------------------
    // FB-18 — a branch join is owed, not paid
    // ------------------------------------------------------------------

    public function test_a_branch_join_raises_a_pending_charge_and_records_no_money(): void
    {
        $hq = $this->paying();
        $end = $this->sub($hq)->current_period_end->toDateString();

        [, $branch, $charge] = $this->withBranchOn($hq);

        $this->assertSame('pending', $charge->status);
        $this->assertSame($branch->id, $charge->tenant_id);
        $this->assertSame('provision', $charge->raised_via);
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count(), 'no money arrived, so no money row');
        $this->assertGreaterThan(0, $this->owed($hq));
        $this->assertSame($end, $this->sub($hq)->current_period_end->toDateString(), 'rule 2: access untouched');
        $this->assertSame(2, (int) $this->sub($hq)->quantity, 'the branch is billed from now on');
        $this->assertStringContainsString($charge->number, AdminAuditLog::where('action', 'subscription.branch_added')->sole()->description);
    }

    private function withBranchOn(Tenant $hq): array
    {
        $branch = $this->store('Branch 2', $hq->account->fresh());

        return [$hq, $branch, BillingCharge::where('account_id', $hq->account_id)->where('kind', 'branch_join')->sole()];
    }

    // ------------------------------------------------------------------
    // D-5 — Record a payment says what it pays for (HTTP)
    // ------------------------------------------------------------------

    public function test_paying_an_open_charge_by_hand_settles_it_and_moves_no_clock(): void
    {
        [$hq, , $charge] = $this->withBranch();
        $end = $this->sub($hq)->current_period_end->toDateString();

        $this->asOperator()->post(route('superadmin.accounts.payment', $hq->account_id), [
            'for' => $charge->id, 'method' => 'upi', 'reference' => 'UTR123', '_once' => (string) Str::uuid(),
        ])->assertSessionHas('status');

        $this->assertSame('paid', $charge->fresh()->status);
        $this->assertSame($charge->id, SubscriptionInvoice::withoutGlobalScopes()->sole()->charge_id);
        $this->assertEquals(0.0, $this->owed($hq));
        $this->assertSame($end, $this->sub($hq)->current_period_end->toDateString());
    }

    public function test_another_accounts_charge_never_resolves_through_this_account(): void
    {
        [$a] = $this->withBranch('A');
        [, , $theirs] = $this->withBranch('B');

        foreach ([
            route('superadmin.accounts.payment', $a->account_id) => ['for' => $theirs->id, 'method' => 'cash'],
            route('superadmin.accounts.charge.void', [$a->account_id, $theirs->id]) => ['reason' => 'x'],
            route('superadmin.accounts.charge.waive', [$a->account_id, $theirs->id]) => ['reason' => 'x'],
        ] as $url => $form) {
            $this->asOperator()->post($url, $form + ['_once' => (string) Str::uuid()])->assertNotFound();
        }

        $this->assertSame('pending', $theirs->fresh()->status, 'isolation: nothing happened to B');
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());

        $preview = $this->asOperator()->postJson(route('superadmin.accounts.preview', $a->account_id), [
            'action' => 'record_payment', 'for' => $theirs->id, 'method' => 'cash',
        ])->json();
        $this->assertStringContainsString('not on this customer', $preview['error']);
    }

    public function test_the_payment_form_refuses_unknown_methods_and_future_dates(): void
    {
        [$hq] = $this->withBranch();

        $this->asOperator()->post(route('superadmin.accounts.payment', $hq->account_id), [
            'for' => 'renewal', 'method' => 'adjustment', '_once' => (string) Str::uuid(),
        ])->assertSessionHasErrors('method');

        $this->asOperator()->post(route('superadmin.accounts.payment', $hq->account_id), [
            'for' => 'renewal', 'method' => 'cash', 'paid_at' => $this->today()->addDays(2)->toDateString(), '_once' => (string) Str::uuid(),
        ])->assertSessionHasErrors('paid_at');

        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------
    // Void / waive / reverse through the doors
    // ------------------------------------------------------------------

    public function test_void_and_waive_need_a_reason_and_do_what_they_say(): void
    {
        [$hq, , $charge] = $this->withBranch();

        $this->asOperator()->post(route('superadmin.accounts.charge.void', [$hq->account_id, $charge->id]), ['_once' => (string) Str::uuid()])
            ->assertSessionHasErrors('reason');
        $this->assertSame('pending', $charge->fresh()->status);

        $this->asOperator()->post(route('superadmin.accounts.charge.waive', [$hq->account_id, $charge->id]), [
            'reason' => 'opening offer', '_once' => (string) Str::uuid(),
        ])->assertSessionHas('status');

        $row = SubscriptionInvoice::withoutGlobalScopes()->sole();
        $this->assertSame('comp', $row->method);
        $this->assertSame($charge->id, $row->charge_id);
        $this->assertEquals(0.0, $this->owed($hq));

        // Already settled: a void now is refused and changes nothing.
        $this->asOperator()->post(route('superadmin.accounts.charge.void', [$hq->account_id, $charge->id]), [
            'reason' => 'late', '_once' => (string) Str::uuid(),
        ])->assertSessionHas('error');
        $this->assertSame('paid', $charge->fresh()->status);
    }

    public function test_reversing_a_payment_through_the_panel_reopens_what_it_paid(): void
    {
        [$hq, , $charge] = $this->withBranch();
        $row = app(Settlement::class)->settle($charge, ['method' => 'cash']);

        $this->asOperator()->post(route('superadmin.accounts.payment.reverse', [$hq->account_id, $row->id]), [
            'reason' => 'bounced', '_once' => (string) Str::uuid(),
        ])->assertSessionHas('status', fn ($m) => str_contains($m, 'owed again'));

        $this->assertSame('pending', $charge->fresh()->status);
        $this->assertEquals((float) $charge->amount, $this->owed($hq));
    }

    // ------------------------------------------------------------------
    // E2-E / E2-F — endings and grants close what is open
    // ------------------------------------------------------------------

    public function test_cancelling_now_voids_what_is_open_but_cancelling_at_period_end_does_not(): void
    {
        [$hq] = $this->withBranch('Later');
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'cancel', ['reason' => 'moving away', 'at_period_end' => true]);
        $this->assertGreaterThan(0, $this->owed($hq), 'still a customer until the period ends');

        [$now, , $charge] = $this->withBranch('Now');
        app(SubscriptionLifecycle::class)->commit($this->sub($now), 'cancel', ['reason' => 'closed the business']);

        $this->assertSame('void', $charge->fresh()->status);
        $this->assertSame('Cancelled: closed the business', $charge->fresh()->void_reason);
        $this->assertEquals(0.0, $this->owed($now));
    }

    public function test_ending_access_voids_what_is_open(): void
    {
        [$hq, , $charge] = $this->withBranch();

        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'force_expire', ['reason' => 'fraud']);

        $this->assertSame('void', $charge->fresh()->status);
        $this->assertEquals(0.0, $this->owed($hq));
    }

    public function test_free_access_voids_the_term_it_covers_but_not_a_branchs_part_period(): void
    {
        [$hq, , $branchCharge] = $this->withBranch();
        $term = app(BillingEngine::class)->raiseTerm($this->sub($hq));

        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'comp', ['months' => 3, 'reason' => 'goodwill']);

        $this->assertSame('void', $term->fresh()->status);
        $this->assertStringStartsWith('Covered by free access', $term->fresh()->void_reason);
        $this->assertSame('pending', $branchCharge->fresh()->status, 'the part period ran before the grant — still owed');
        $this->assertEquals((float) $branchCharge->amount, $this->owed($hq));
    }

    // ------------------------------------------------------------------
    // Previews show owed moving, and the D-4 warning
    // ------------------------------------------------------------------

    public function test_previews_show_owed_moving_and_leave_nothing_behind(): void
    {
        [$hq, , $charge] = $this->withBranch();
        $owed = $this->owed($hq);
        $url = route('superadmin.accounts.preview', $hq->account_id);

        $cancel = $this->asOperator()->postJson($url, ['action' => 'cancel', 'reason' => 'x'])->json();
        $this->assertEquals(['from' => $owed, 'to' => 0], $cancel['changes']['owed']);

        $void = $this->asOperator()->postJson($url, ['action' => 'void_charge', 'charge' => $charge->id, 'reason' => 'x'])->json();
        $this->assertNull($void['error']);
        $this->assertEquals(0, $void['changes']['owed']['to']);

        $pay = $this->asOperator()->postJson($url, ['action' => 'record_payment', 'for' => $charge->id, 'method' => 'cash'])->json();
        $this->assertNull($pay['error']);
        $this->assertSame(1, $pay['ledger_rows_added']);

        $this->assertSame('pending', $charge->fresh()->status, 'previews are undone');
        $this->assertSame(1, BillingCharge::count());
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());
        $this->assertEquals($owed, $this->owed($hq));
    }

    public function test_the_preview_warns_over_the_bill_and_the_commit_still_needs_the_tick(): void
    {
        $hq = $this->paying();
        $url = route('superadmin.accounts.preview', $hq->account_id);

        $preview = $this->asOperator()->postJson($url, [
            'action' => 'record_payment', 'for' => 'renewal', 'amount' => 4990, 'method' => 'cash',
        ])->json();

        $this->assertNull($preview['error'], 'the operator sees what WOULD happen');
        $this->assertTrue(collect($preview['warnings'])->contains(fn ($w) => str_contains($w, 'MORE than the bill')));
        $this->assertArrayHasKey('current_period_end', $preview['changes']);

        $this->asOperator()->post(route('superadmin.accounts.payment', $hq->account_id), [
            'for' => 'renewal', 'amount' => 4990, 'method' => 'cash', '_once' => (string) Str::uuid(),
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'MORE than the bill'));
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());

        $this->asOperator()->post(route('superadmin.accounts.payment', $hq->account_id), [
            'for' => 'renewal', 'amount' => 4990, 'method' => 'cash', 'confirm_over' => 1, '_once' => (string) Str::uuid(),
        ])->assertSessionHas('status');
        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------
    // The owner sees what they owe, with the working; never another's
    // ------------------------------------------------------------------

    public function test_the_owner_sees_their_owed_charges_and_not_another_accounts(): void
    {
        [$hq, , $mine] = $this->withBranch('Mine');
        [, , $theirs] = $this->withBranch('Theirs');
        $owner = User::where('tenant_id', $hq->id)->where('role', 'store_admin')->firstOrFail();

        $this->actingAs($owner)->get(route('tenant.billing.index'))
            ->assertOk()
            ->assertSee($mine->number)
            ->assertSee('How this was worked out')
            ->assertDontSee($theirs->number);
    }
}
