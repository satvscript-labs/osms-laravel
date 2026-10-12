<?php

namespace Tests\Feature;

use App\Mail\TrialStatusMail;
use App\Models\Account;
use App\Models\AdminAuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use App\Services\PriceResolver;
use App\Services\StoreClosure;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * E1 — every writer goes through the engine; the clock is stored (09_E1_PLAN.md).
 *
 *   E1.1  current_period_start is written on every period change
 *   E1.2  every engine write is locked (FB-15)
 *   E1.3  the writers that lived outside the engine now live inside it, audited
 *   E1.4  a period switch takes effect from the NEXT renewal, from both doors (FB-19)
 *   E1.9  billing mail reaches the payer, even after their first store is purged
 *   E1.10 endings reach the gateway (rule 5)
 *
 * Assertions are about what the customer can do and what the operator can see:
 * access, rupees, the audit trail — not merely which column moved (rule 7).
 */
class Phase84E1EngineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->admin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
        $this->actingAs($this->admin);

        $this->gateway = new FakeGateway();
        $this->app->instance(BillingService::class, $this->gateway);
    }

    private function today(): Carbon
    {
        return Carbon::today(config('billing.timezone'));
    }

    private function lifecycle(): SubscriptionLifecycle
    {
        return app(SubscriptionLifecycle::class);
    }

    private function store(string $name, ?Account $account = null): Tenant
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        return app(StoreProvisioner::class)->provision($owner, ['store_name' => $name], $account);
    }

    private function ownerOf(Tenant $tenant): User
    {
        return User::where('tenant_id', $tenant->id)->where('role', 'store_admin')->firstOrFail();
    }

    private function sub(Tenant|Account $of): Subscription
    {
        $accountId = $of instanceof Account ? $of->id : $of->account_id;

        return Subscription::withoutGlobalScopes()->where('account_id', $accountId)->firstOrFail();
    }

    /** Active, monthly, paid to $daysAhead from today (a fixture: written directly). */
    private function paying(string $name = 'HQ', int $daysAhead = 20): Tenant
    {
        $hq = $this->store($name);
        $this->sub($hq)->forceFill([
            'status' => 'active', 'interval' => 'monthly',
            'current_period_end' => $this->today()->addDays($daysAhead),
        ])->save();

        return $hq;
    }

    private function auditCount(string $action): int
    {
        return AdminAuditLog::where('action', $action)->count();
    }

    // ------------------------------------------------------------------
    // E1.3 W3 — the trial is started by the engine
    // ------------------------------------------------------------------

    public function test_a_new_store_starts_a_trial_with_its_period_start_recorded(): void
    {
        $sub = $this->sub($this->store('Fresh'));

        $this->assertSame('trialing', $sub->status);
        $this->assertSame('active', $sub->accessState());
        $this->assertSame($this->today()->toDateString(), $sub->current_period_start->toDateString());
        $this->assertSame($this->today()->addDays(14)->toDateString(), $sub->current_period_end->toDateString());
        $this->assertNotNull($sub->plan_id);
        $this->assertNotNull(Account::find($sub->account_id)->trial_used_at);
    }

    public function test_a_second_branch_joins_the_payers_clock_never_a_second_one(): void
    {
        $hq = $this->store('HQ');
        $this->store('Branch 2', $hq->account->fresh());

        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->count());
        $this->assertSame(2, (int) $this->sub($hq)->quantity);
    }

    public function test_starting_a_trial_twice_for_one_account_is_a_no_op_and_locks_the_account(): void
    {
        $hq = $this->store('HQ');

        DB::enableQueryLog();
        $again = $this->lifecycle()->startTrial($hq->fresh());
        $log = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertNull($again, 'an account with a clock never gets a second one (L-2)');
        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->count());

        // The account row is read FOR UPDATE before the check (MariaDB/MySQL;
        // SQLite has no row locks and its grammar omits the clause).
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertMatchesRegularExpression('/from `accounts`.*for update/is', $log);
        }
    }

    public function test_provision_terms_go_through_the_engine_and_zero_trial_days_ends_today(): void
    {
        $shop = app(StoreProvisioner::class)->provisionAsOperator(
            ['name' => 'Spot Payer', 'email' => 'spot@example.test'],
            ['store_name' => 'Paid On The Spot'],
            ['plan_code' => 'basic', 'trial_days' => 0],
        )['tenant'];
        $sub = $this->sub($shop);

        $this->assertSame($this->today()->toDateString(), $sub->current_period_end->toDateString());
        $this->assertSame($this->today()->toDateString(), $sub->current_period_start->toDateString());
    }

    // ------------------------------------------------------------------
    // E1.3 W6 — the nightly clock, audited and re-checked under the lock
    // ------------------------------------------------------------------

    public function test_the_nightly_lapse_is_audited_as_system(): void
    {
        $hq = $this->paying('Lapsing', daysAhead: -30);

        Artisan::call('subscriptions:reconcile');

        $sub = $this->sub($hq);
        $this->assertSame('past_due', $sub->status);
        $this->assertSame('locked', $sub->accessState());

        $row = AdminAuditLog::where('action', 'subscription.lapsed')->firstOrFail();
        $this->assertSame('system', $row->meta['actor']);
        $this->assertSame('active', $row->meta['before']['status']);
        $this->assertSame('past_due', $row->meta['after']['status']);

        // E1.12 — named as the system, not as whichever operator was signed in.
        $this->assertSame('System', $row->actorLabel());
        $this->get(route('superadmin.audit.index'))->assertOk()->assertSee('System');
    }

    public function test_churn_is_audited_and_stops_the_gateway_immediately(): void
    {
        $hq = $this->paying('Churning', daysAhead: -60);
        $this->sub($hq)->forceFill(['status' => 'past_due', 'razorpay_subscription_id' => 'sub_churn'])->save();

        Artisan::call('subscriptions:reconcile');

        $this->assertSame('canceled', $this->sub($hq)->status);
        $this->assertSame(1, $this->auditCount('subscription.churned'));
        $this->assertSame([['sub_churn', false]], $this->gateway->cancelled, 'cancelled immediately, not at cycle end');
        $this->assertSame(1, $this->auditCount('subscription.gateway_cancelled'));
    }

    public function test_an_expired_trial_is_audited_and_its_owner_is_told(): void
    {
        Mail::fake();
        $shop = $this->store('Trial Over');
        $this->sub($shop)->forceFill(['current_period_end' => $this->today()->subDays(2)])->save();

        Artisan::call('subscriptions:reconcile');

        $this->assertSame('canceled', $this->sub($shop)->status);
        $this->assertSame(1, $this->auditCount('subscription.trial_expired'));
        Mail::assertQueued(TrialStatusMail::class, fn ($m) => $m->hasTo($this->ownerOf($shop)->email));
    }

    public function test_the_nightly_job_never_overrules_a_change_made_after_it_read_its_list(): void
    {
        // TOCTOU: the job's candidate list is read, then an operator renews the
        // customer, then the job reaches them. The engine re-checks on the locked
        // row, so the renewal stands.
        $hq = $this->paying('Renewed Meanwhile', daysAhead: -30);
        $stale = $this->sub($hq);   // what the nightly job read

        $this->lifecycle()->commit($this->sub($hq), 'renew', ['amount' => 499, 'method' => 'cash']);

        $this->assertFalse($this->lifecycle()->lapse($stale));
        $this->assertSame('active', $this->sub($hq)->status);
        $this->assertSame('active', $this->sub($hq)->accessState());
        $this->assertSame(0, $this->auditCount('subscription.lapsed'));
    }

    public function test_an_operator_override_is_never_touched_by_the_nightly_job(): void
    {
        $hq = $this->paying('Comped', daysAhead: -30);
        $this->sub($hq)->forceFill(['override_kind' => 'suspension', 'override_until' => null, 'status' => 'past_due'])->save();
        $this->sub($hq)->forceFill(['current_period_end' => $this->today()->subDays(90)])->save();

        Artisan::call('subscriptions:reconcile');

        $this->assertSame('past_due', $this->sub($hq)->status);
        $this->assertSame(0, $this->auditCount('subscription.churned'));
    }

    // ------------------------------------------------------------------
    // E1.9 — billing mail reaches the payer
    // ------------------------------------------------------------------

    public function test_trial_mail_reaches_the_payer_even_after_their_first_store_is_purged(): void
    {
        Mail::fake();
        $hq = $this->store('Origin');
        $branch = $this->store('Branch', $hq->account->fresh());
        $account = $hq->account->fresh();

        // The origin store is gone (P.1 keeps the clock with tenant_id NULL); only
        // the branch is still trading.
        Subscription::withoutGlobalScopes()->where('account_id', $account->id)->update(['tenant_id' => null]);
        Tenant::whereKey($hq->id)->update(['store_status' => 'closed']);
        $this->sub($account)->forceFill(['current_period_end' => $this->today()->addDays(3)])->save();

        Artisan::call('subscriptions:reconcile');

        Mail::assertQueued(TrialStatusMail::class, fn ($m) => $m->hasTo($account->owner->email)
            && $m->tenant->is($branch));
    }

    // ------------------------------------------------------------------
    // E1.3 W2 — gateway updates are locked and audited
    // ------------------------------------------------------------------

    public function test_an_applied_gateway_update_is_audited_and_starts_the_period(): void
    {
        $hq = $this->paying('Online', daysAhead: 0);
        $oldEnd = $this->sub($hq)->current_period_end->copy();

        $this->assertTrue($this->lifecycle()->applyGatewayEntitlement(
            $this->sub($hq), 'active', $oldEnd->copy()->addMonth(), 'monthly',
        ));

        $sub = $this->sub($hq);
        $this->assertSame($oldEnd->toDateString(), $sub->current_period_start->toDateString(), 'a renewal starts where the old period ended');
        $row = AdminAuditLog::where('action', 'subscription.gateway_applied')->firstOrFail();
        $this->assertSame('gateway', $row->meta['actor']);
    }

    // ------------------------------------------------------------------
    // E1.3 W7 / W8 — the owner's own actions
    // ------------------------------------------------------------------

    public function test_opening_a_checkout_changes_nothing_about_access_and_never_mints_a_clock(): void
    {
        // A lapsed customer re-subscribing: status stays as it is until the money lands.
        $hq = $this->paying('Back Again', daysAhead: -3);
        $this->sub($hq)->forceFill(['status' => 'past_due'])->save();
        $before = $this->sub($hq)->accessState();

        $this->actingAs($this->ownerOf($hq))
            ->post(route('tenant.billing.subscribe'), ['tier' => 'basic', 'interval' => 'yearly'])
            ->assertOk();

        $sub = $this->sub($hq);
        $this->assertSame('past_due', $sub->status, 'rule 2: an unpaid checkout changes nothing');
        $this->assertSame($before, $sub->accessState());
        $this->assertSame('sub_fake_1', $sub->razorpay_subscription_id);
        $this->assertSame('yearly', $sub->pending_interval);
        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->count());
        $this->assertSame('owner', AdminAuditLog::where('action', 'subscription.checkout_started')->firstOrFail()->meta['actor']);
    }

    public function test_a_checkout_from_a_branch_whose_origin_was_purged_links_to_the_same_clock(): void
    {
        $hq = $this->store('Origin');
        $this->store('Branch', $hq->account->fresh());
        Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->update(['tenant_id' => null]);

        $this->lifecycle()->linkCheckout($this->sub($hq), 'sub_branch', 'monthly');

        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->count());
        $this->assertSame('sub_branch', $this->sub($hq)->razorpay_subscription_id);
    }

    public function test_the_owners_interval_switch_and_cancel_are_audited(): void
    {
        $hq = $this->paying('Owner Acts');

        $this->actingAs($this->ownerOf($hq))->post(route('tenant.billing.change-interval'), ['interval' => 'yearly']);
        $this->actingAs($this->ownerOf($hq))->post(route('tenant.billing.cancel'));

        $sub = $this->sub($hq);
        $this->assertSame('monthly', $sub->interval, 'the running period is untouched');
        $this->assertSame('yearly', $sub->pending_interval);
        $this->assertTrue((bool) $sub->cancel_at_period_end);
        $this->assertSame('active', $sub->accessState(), 'they keep what they paid for');
        $this->assertSame(1, $this->auditCount('subscription.owner_interval'));
        $this->assertSame(1, $this->auditCount('subscription.owner_cancel'));
    }

    // ------------------------------------------------------------------
    // E1.10 — operator endings reach the gateway
    // ------------------------------------------------------------------

    public function test_operator_endings_reach_the_gateway_exactly_once(): void
    {
        $atEnd = $this->paying('Cancel At End');
        $this->sub($atEnd)->forceFill(['razorpay_subscription_id' => 'sub_end'])->save();
        $now = $this->paying('End Now');
        $this->sub($now)->forceFill(['razorpay_subscription_id' => 'sub_now'])->save();
        $legacy = $this->paying('Legacy Screen');
        $this->sub($legacy)->forceFill(['razorpay_subscription_id' => 'sub_legacy'])->save();

        $this->lifecycle()->commit($this->sub($atEnd), 'cancel', ['reason' => 'moving on', 'at_period_end' => true]);
        $this->lifecycle()->commit($this->sub($now), 'force_expire', ['reason' => 'abuse']);
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.subscription.cancel', $legacy), ['reason' => 'old screen']);

        $this->assertSame([['sub_end', true], ['sub_now', false], ['sub_legacy', false]], $this->gateway->cancelled);
    }

    public function test_the_preview_says_autopay_ends_with_a_cancellation_and_does_not_come_back(): void
    {
        $hq = $this->paying('Autopay');
        $this->sub($hq)->forceFill(['razorpay_subscription_id' => 'sub_auto'])->save();

        $cancel = $this->lifecycle()->preview($this->sub($hq), 'cancel', ['reason' => 'x', 'at_period_end' => true]);
        $this->assertTrue(collect($cancel['warnings'])->contains(fn ($w) => str_contains($w, 'cancels their Razorpay autopay')));

        $this->lifecycle()->commit($this->sub($hq), 'cancel', ['reason' => 'moving on', 'at_period_end' => true]);

        $back = $this->lifecycle()->preview($this->sub($hq), 'reactivate', ['reason' => 'changed their mind']);
        $this->assertTrue(collect($back['warnings'])->contains(fn ($w) => str_contains($w, 'does not restart')),
            'reactivating restores access, but the operator must know autopay is gone');
    }

    public function test_a_gateway_failure_never_blocks_or_undoes_the_operator(): void
    {
        $hq = $this->paying('Gateway Down');
        $this->sub($hq)->forceFill(['razorpay_subscription_id' => 'sub_down'])->save();
        $this->gateway->failCancels = true;

        $this->lifecycle()->commit($this->sub($hq), 'force_expire', ['reason' => 'ended']);

        $this->assertSame('canceled', $this->sub($hq)->status, 'the decision stands');
        $this->assertSame(1, $this->auditCount('subscription.gateway_cancel_failed'));
    }

    // ------------------------------------------------------------------
    // E1.1 / E1.4 — the clock and the pending period
    // ------------------------------------------------------------------

    public function test_every_new_period_records_its_start(): void
    {
        $hq = $this->paying('Clock', daysAhead: 10);
        $base = $this->sub($hq)->current_period_end->copy();

        $this->lifecycle()->commit($this->sub($hq), 'renew', ['amount' => 499, 'method' => 'cash']);
        $this->assertSame($base->toDateString(), $this->sub($hq)->current_period_start->toDateString());

        $lapsed = $this->paying('Clock Lapsed', daysAhead: -5);
        $this->sub($lapsed)->forceFill(['status' => 'past_due'])->save();
        $this->lifecycle()->commit($this->sub($lapsed), 'mark_paid', ['reason' => 'cash', 'amount' => 499]);
        $this->assertSame($this->today()->toDateString(), $this->sub($lapsed)->current_period_start->toDateString(), 'covered from the day paid (D-7)');
    }

    public function test_switching_period_waits_for_the_next_renewal(): void
    {
        $hq = $this->paying('Switcher');
        $sub = $this->sub($hq);
        $mrrBefore = \App\Support\Mrr::monthlyValue($sub);
        $endBefore = $sub->current_period_end->toDateString();

        $this->lifecycle()->commit($sub, 'switch_interval', ['interval' => 'yearly']);

        $sub = $this->sub($hq);
        $this->assertSame('monthly', $sub->interval, 'the running period is not re-priced (FB-19)');
        $this->assertSame('yearly', $sub->pending_interval);
        $this->assertSame($endBefore, $sub->current_period_end->toDateString());
        $this->assertEquals($mrrBefore, \App\Support\Mrr::monthlyValue($sub), 'MRR does not jump mid-cycle');
        $this->assertEquals(4999.0, app(PriceResolver::class)->renewalPrice($sub), 'the next period is priced at the pending interval');

        // The renewal lands it.
        $this->lifecycle()->commit($sub, 'renew', ['amount' => 4999, 'method' => 'cash']);
        $sub = $this->sub($hq);
        $this->assertSame('yearly', $sub->interval);
        $this->assertNull($sub->pending_interval);
        $this->assertSame(Carbon::parse($endBefore)->addYear()->toDateString(), $sub->current_period_end->toDateString());
    }

    public function test_choosing_the_current_period_cancels_a_pending_switch(): void
    {
        $hq = $this->paying('Undo');
        $this->lifecycle()->commit($this->sub($hq), 'switch_interval', ['interval' => 'yearly']);
        $this->lifecycle()->commit($this->sub($hq), 'switch_interval', ['interval' => 'monthly']);

        $this->assertNull($this->sub($hq)->pending_interval);
    }

    public function test_mark_as_paid_lands_a_pending_switch_and_prices_the_new_period_at_it(): void
    {
        $hq = $this->paying('Pending Then Paid', daysAhead: -5);
        $this->lifecycle()->commit($this->sub($hq), 'switch_interval', ['interval' => 'yearly']);
        $this->sub($hq)->forceFill(['status' => 'past_due'])->save();

        $this->lifecycle()->commit($this->sub($hq), 'mark_paid', ['reason' => 'paid yearly', 'amount' => '']);

        $sub = $this->sub($hq);
        $this->assertSame('yearly', $sub->interval);
        $this->assertNull($sub->pending_interval);
        $this->assertSame($this->today()->addYear()->toDateString(), $sub->current_period_end->toDateString());
        $this->assertEquals(4999.0, (float) SubscriptionInvoice::withoutGlobalScopes()
            ->where('account_id', $hq->account_id)->where('reason', 'paid yearly')->value('amount'));
    }

    // ------------------------------------------------------------------
    // E1 audit — found while auditing the build
    // ------------------------------------------------------------------

    public function test_a_pending_switch_is_visible_to_the_operator_and_undoable_by_the_owner(): void
    {
        $hq = $this->paying('Visible');
        $this->lifecycle()->commit($this->sub($hq), 'switch_interval', ['interval' => 'yearly']);

        // The operator sees it on the customer page, not only in the audit trail.
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('superadmin.accounts.show', $hq->account_id))
            ->assertSee('monthly → yearly from next renewal');

        // The owner sees it and can undo it (choosing the period they are on).
        $owner = $this->ownerOf($hq);
        $this->actingAs($owner)->get(route('tenant.billing.index'))
            ->assertSee('Switching to Yearly at your next renewal')
            ->assertSee('Keep Monthly');

        // Undoing says "you stay", never "will switch" (found in the E1 manual pass).
        $this->actingAs($owner)->post(route('tenant.billing.change-interval'), ['interval' => 'monthly'])
            ->assertSessionHas('status', "You'll stay on monthly billing. The switch is cancelled.");

        $this->assertNull($this->sub($hq)->pending_interval);
        $this->assertSame('monthly', $this->sub($hq)->interval);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'subscription.owner_interval',
            'description' => 'Owner cancelled the pending switch and stays on monthly billing',
        ]);
    }

    public function test_the_operator_is_told_autopay_does_not_follow_their_switch(): void
    {
        $hq = $this->paying('Autopay Switch');
        $this->sub($hq)->forceFill(['razorpay_subscription_id' => 'sub_auto2'])->save();

        $preview = $this->lifecycle()->preview($this->sub($hq), 'switch_interval', ['interval' => 'yearly']);

        $this->assertTrue(collect($preview['warnings'])->contains(fn ($w) => str_contains($w, 'autopay')));
    }

    public function test_the_preview_reads_the_fresh_row_not_a_stale_copy(): void
    {
        $hq = $this->paying('Stale Copy');
        $stale = $this->sub($hq);   // read at page load…
        $this->sub($hq)->forceFill(['status' => 'past_due'])->save();   // …then a webhook moved it

        $preview = $this->lifecycle()->preview($stale, 'switch_interval', ['interval' => 'yearly']);

        $this->assertSame('past_due', $preview['before']['status'], 'before is the current state');
        $this->assertArrayNotHasKey('status', $preview['changes'], 'no phantom status change');
    }

    public function test_a_rolled_back_decision_never_reaches_the_gateway(): void
    {
        $hq = $this->paying('Rolled Back');
        $this->sub($hq)->forceFill(['razorpay_subscription_id' => 'sub_rb'])->save();

        try {
            DB::transaction(function () use ($hq) {
                $this->lifecycle()->commit($this->sub($hq), 'force_expire', ['reason' => 'x']);
                throw new \RuntimeException('something later failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('active', $this->sub($hq)->status, 'the ending rolled back…');
        $this->assertSame([], $this->gateway->cancelled, '…so Razorpay was never told to stop');
    }

    public function test_the_switch_preview_says_from_next_renewal_and_changes_nothing_now(): void
    {
        $hq = $this->paying('Preview');

        $preview = $this->lifecycle()->preview($this->sub($hq), 'switch_interval', ['interval' => 'yearly']);

        $this->assertSame(['from' => null, 'to' => 'yearly'], $preview['changes']['pending_interval']);
        $this->assertArrayNotHasKey('interval', $preview['changes']);
        $this->assertArrayNotHasKey('effective_price', $preview['changes']);
        $this->assertNull($this->sub($hq)->pending_interval, 'a preview is rolled back');
    }
}

/**
 * Stands in for Razorpay. Records what it was asked to do; never calls out.
 */
class FakeGateway extends BillingService
{
    /** @var list<array{0: string, 1: bool}> */
    public array $cancelled = [];

    public bool $failCancels = false;

    private int $created = 0;

    public function isConfigured(): bool
    {
        return true;
    }

    public function createSubscription(Tenant $tenant, string $tier, string $interval = 'monthly'): array
    {
        return ['subscription_id' => 'sub_fake_' . (++$this->created)];
    }

    public function changeInterval(string $razorpaySubscriptionId, string $tier, string $interval): void {}

    public function cancelSubscription(string $razorpaySubscriptionId, bool $atCycleEnd = true): void
    {
        if ($this->failCancels) {
            throw new \RuntimeException('Razorpay is down');
        }

        $this->cancelled[] = [$razorpaySubscriptionId, $atCycleEnd];
    }

    public function publicKey(): ?string
    {
        return 'rzp_test_fake';
    }
}
