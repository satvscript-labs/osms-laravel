<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentRecorder;
use App\Services\StoreClosure;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use App\Support\Metrics;
use App\Support\Mrr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Track A audit (P0–P6) — a regression test per finding.
 *
 * Every case below FAILED before its fix; each was reproduced with a probe
 * before being believed. The two that mattered most (A-01, A-02) are the same
 * shape as the defect the P0–P3 audit found: a lever the operator pulls, that
 * reports success, and does nothing.
 *
 * See `_artifacts/platform/91_TRACK_A_AUDIT.md` for the full write-up.
 */
class Phase78TrackAAuditFixesTest extends TestCase
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

    private function tenant(string $name = 'Audit Optical', ?\App\Models\Account $account = null): Tenant
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        return app(StoreProvisioner::class)->provision($owner, ['store_name' => $name], $account);
    }

    /** A customer whose renewal failed and whose paid period has run out. */
    private function lapsed(string $name = 'Audit Optical'): Subscription
    {
        $subscription = $this->tenant($name)->account->subscription;

        $subscription->forceFill([
            'status' => 'past_due',
            'interval' => 'monthly',
            'current_period_end' => now()->subDays(10),
        ])->save();

        return $subscription->fresh();
    }

    private function lifecycle(): SubscriptionLifecycle
    {
        return app(SubscriptionLifecycle::class);
    }

    /*
    |--------------------------------------------------------------------------
    | A-01 / A-02 — the two levers that reported success and did nothing
    |--------------------------------------------------------------------------
    */

    public function test_marking_a_payment_received_actually_restores_access(): void
    {
        $subscription = $this->lapsed();
        $this->assertSame('locked', $subscription->accessState());

        $this->lifecycle()->commit($subscription, 'mark_paid', [
            'reason' => 'paid by bank transfer', 'amount' => 499, 'method' => 'bank_transfer',
        ]);

        $subscription->refresh();

        // The whole point. Before the fix this stayed `locked`: status went to
        // `active` but the clock never moved, and `active` past its boundary
        // and beyond grace is locked. The operator was told the payment was
        // recorded while the customer still could not sign in.
        $this->assertSame('active', $subscription->accessState());
        $this->assertTrue($subscription->current_period_end->isFuture());
    }

    public function test_marking_a_payment_received_survives_the_nightly_reconcile(): void
    {
        $subscription = $this->lapsed();

        $this->lifecycle()->commit($subscription, 'mark_paid', [
            'reason' => 'cash', 'amount' => 499, 'method' => 'cash',
        ]);

        $this->artisan('subscriptions:reconcile')->run();

        // BUG-P01's promise — a human decision outlives the robot — was broken
        // here: the override was pinned to the ALREADY-EXPIRED period end, so
        // it was dead on arrival and that night's job put them back to past_due.
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertTrue($subscription->fresh()->hasActiveOverride());
    }

    public function test_waiving_a_cycle_restores_access_and_records_a_zero_rupee_row(): void
    {
        $subscription = $this->lapsed();

        $this->lifecycle()->commit($subscription, 'waive', ['reason' => 'goodwill, long-standing customer']);
        $subscription->refresh();

        $this->assertSame('active', $subscription->accessState());
        $this->assertTrue($subscription->current_period_end->isFuture());

        // A waiver is a grant, so it is a ₹0 ledger row with a reason — never
        // an absent one (BUG-P04's rule, applied to this lever too).
        $comp = SubscriptionInvoice::withoutGlobalScopes()
            ->where('account_id', $subscription->account_id)->where('method', 'comp')->firstOrFail();
        $this->assertEquals(0.0, (float) $comp->amount);
        $this->assertNotNull($comp->period_end);
    }

    public function test_a_zero_amount_mark_paid_is_refused_rather_than_faked(): void
    {
        $subscription = $this->lapsed();

        $this->expectException(\InvalidArgumentException::class);
        $this->lifecycle()->commit($subscription, 'mark_paid', [
            'reason' => 'x', 'amount' => 0, 'method' => 'cash',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | A-03 — the crash
    |--------------------------------------------------------------------------
    */

    public function test_mark_paid_works_when_the_amount_field_is_absent(): void
    {
        $subscription = $this->lapsed();

        // `amount` is nullable in validation, so it is simply MISSING from the
        // payload when the operator leaves it blank. This raised "Undefined
        // array key" and 500'd the action.
        $this->lifecycle()->commit($subscription, 'mark_paid', ['reason' => 'they paid the list price']);

        $this->assertSame('active', $subscription->fresh()->accessState());
        $this->assertDatabaseCount('subscription_invoices', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | A-04 — the audit trail's missing half
    |--------------------------------------------------------------------------
    */

    public function test_logging_out_mid_impersonation_still_records_the_exit(): void
    {
        $tenant = $this->tenant('Watch Me');
        $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.accounts.impersonate', [$tenant->account_id, $owner]), ['reason' => 'support']);

        $this->post(route('logout'))->assertRedirect(route('superadmin.dashboard'));

        // An entry with no exit reads as a session that never ended — the worst
        // possible shape for the one trail that exists to answer "who was in
        // there, and for how long?".
        $exit = AdminAuditLog::where('action', 'impersonation.ended')->firstOrFail();
        $this->assertSame('logout', $exit->meta['ended_by']);
        $this->assertSame($this->admin->id, $exit->admin_user_id);

        // And the operator keeps their own session rather than losing it.
        $this->assertAuthenticatedAs($this->admin);
    }

    /*
    |--------------------------------------------------------------------------
    | A-05 / A-11 — closure and money
    |--------------------------------------------------------------------------
    */

    public function test_a_customer_with_no_open_store_is_not_counted_as_revenue(): void
    {
        $tenant = $this->tenant('Only Shop');
        $subscription = $tenant->account->subscription;
        $subscription->forceFill([
            'status' => 'active', 'interval' => 'monthly',
            'negotiated_price' => 750, 'negotiated_interval' => 'monthly', 'negotiated_reason' => 't',
            'current_period_end' => now()->addDays(20),
        ])->save();

        $this->assertEquals(750.0, Mrr::monthlyValue($subscription->fresh()));

        app(StoreClosure::class)->close($tenant, 'shop sold');

        // Closure lives on the store and money lives on the account, so this
        // customer contributed to MRR forever after their last shop shut.
        $this->assertEquals(0.0, Mrr::monthlyValue($subscription->fresh()));
    }

    public function test_closing_one_branch_does_not_stop_the_money_for_the_others(): void
    {
        $first = $this->tenant('Main');
        $account = $first->account;
        $second = $this->tenant('Branch', $account);

        $subscription = $account->subscription;
        $subscription->forceFill([
            'status' => 'active', 'interval' => 'monthly',
            'negotiated_price' => 750, 'negotiated_interval' => 'monthly', 'negotiated_reason' => 't',
            'current_period_end' => now()->addDays(20),
        ])->save();

        app(StoreClosure::class)->close($second, 'branch shut');

        // The deliberate half of the same rule — one closure must not cost the
        // customer the product they still use elsewhere.
        $this->assertEquals(750.0, Mrr::monthlyValue($subscription->fresh()));
    }

    public function test_a_closed_store_stops_counting_toward_the_bill(): void
    {
        $first = $this->tenant('Main');
        $account = $first->account;
        $second = $this->tenant('Branch', $account);

        $this->assertSame(2, $account->billableStores()->count());

        app(StoreClosure::class)->close($second, 'shut');

        $this->assertSame(1, $account->billableStores()->count());

        // Reopening restores it WITHOUT clobbering a separate "exclude from
        // billing" decision, which is why closure is filtered rather than
        // flipping is_billable.
        app(StoreClosure::class)->reopen($second->fresh(), 'came back');
        $this->assertSame(2, $account->billableStores()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | A-06 — never delete an operator
    |--------------------------------------------------------------------------
    */

    public function test_purging_a_store_detaches_operators_instead_of_deleting_them(): void
    {
        $tenant = $this->tenant('Doomed');
        $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $operator = User::factory()->create(['role' => 'superadmin', 'tenant_id' => $tenant->id]);

        app(StoreClosure::class)->close($tenant, 'closing');
        $tenant->forceFill(['purge_after' => now()->subDay()])->save();
        app(StoreClosure::class)->purge($tenant->fresh(), 'window elapsed');

        // Tidying up a shop must never destroy the platform owner's own login.
        $this->assertDatabaseHas('users', ['id' => $operator->id, 'tenant_id' => null]);

        // The store's own people still go, as before.
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | A-07 / A-10 — two flattering numbers
    |--------------------------------------------------------------------------
    */

    public function test_a_suspended_customer_is_not_reported_as_money_you_are_owed(): void
    {
        $tenant = $this->tenant();
        $subscription = $tenant->account->subscription;
        $subscription->forceFill([
            'status' => 'active', 'interval' => 'monthly',
            'negotiated_price' => 900, 'negotiated_interval' => 'monthly', 'negotiated_reason' => 't',
            'current_period_end' => now()->addDays(30),
        ])->save();

        $this->lifecycle()->commit($subscription, 'suspend', ['reason' => 'abuse']);

        // Suspension is expressed as past_due + an override, so every customer
        // you switched off was appearing as an unpaid debt.
        $this->assertSame(0, app(Metrics::class)->collection()['overdue_count']);
        $this->assertEquals(0.0, app(Metrics::class)->collection()['overdue_amount']);
    }

    public function test_a_trial_is_not_counted_as_money_expected(): void
    {
        $this->tenant('Trial Shop');   // trialing, ends inside the horizon

        $collection = app(Metrics::class)->collection();

        // A trial has never paid a rupee. Counting it as expected revenue is
        // the most flattering possible reading of a trial (playbook §9).
        $this->assertSame(0, $collection['due_soon_count']);
        $this->assertEquals(0.0, $collection['due_soon_amount']);
    }

    /*
    |--------------------------------------------------------------------------
    | A-08 / A-09 — force-expire
    |--------------------------------------------------------------------------
    */

    public function test_force_expire_values_the_lost_revenue_like_a_cancellation(): void
    {
        foreach (['force_expire' => 'Forced', 'cancel' => 'Cancelled'] as $action => $name) {
            $tenant = $this->tenant($name);
            $subscription = $tenant->account->subscription;
            $subscription->forceFill([
                'status' => 'active', 'interval' => 'monthly',
                'negotiated_price' => 600, 'negotiated_interval' => 'monthly', 'negotiated_reason' => 't',
                'current_period_end' => now()->addDays(30),
            ])->save();

            $this->lifecycle()->commit($subscription, $action, ['reason' => 'ending it']);

            // Same commercial event, same customer, same value — force_expire
            // recorded ₹0 because it moved the period end in the same save.
            $this->assertEquals(600.0, (float) $subscription->fresh()->churned_mrr,
                "{$action} must value the loss");
        }
    }

    public function test_force_expire_uses_the_billing_calendar(): void
    {
        $tenant = $this->tenant();
        $subscription = $tenant->account->subscription;
        $subscription->forceFill(['status' => 'active', 'current_period_end' => now()->addDays(30)])->save();

        $this->lifecycle()->commit($subscription, 'force_expire', ['reason' => 'fraud']);

        // Yesterday in Asia/Kolkata, like every other clock operation. In UTC
        // it could land two calendar days back in IST terms.
        $this->assertSame(
            now(config('billing.timezone'))->subDay()->toDateString(),
            $subscription->fresh()->current_period_end->toDateString(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | A-12 — receipt numbering past 9999
    |--------------------------------------------------------------------------
    */

    public function test_receipt_numbers_keep_counting_past_four_digits(): void
    {
        $tenant = $this->tenant();
        $subscription = $tenant->account->subscription;
        $year = now(config('billing.timezone'))->year;

        app(PaymentRecorder::class)->record($subscription, 100, 'cash');
        SubscriptionInvoice::withoutGlobalScopes()->latest('id')->first()
            ->forceFill(['receipt_no' => "OSMS-{$year}-9999"])->save();

        $next = app(PaymentRecorder::class)->record($subscription, 100, 'cash');
        $this->assertSame("OSMS-{$year}-10000", $next->receipt_no);

        // The bug only bites on the SECOND one past the boundary: a string sort
        // reads "10000" as lower than "9999", so the counter sticks and mints
        // 10000 again — duplicate numbers on real receipts, silently.
        $after = app(PaymentRecorder::class)->record($subscription, 100, 'cash');
        $this->assertSame("OSMS-{$year}-10001", $after->receipt_no);
    }

    /*
    |--------------------------------------------------------------------------
    | A-13 — a refusal dressed as a success
    |--------------------------------------------------------------------------
    */

    public function test_a_closed_store_cannot_be_viewed(): void
    {
        $tenant = $this->tenant('Closed Shop');
        $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        app(StoreClosure::class)->close($tenant, 'gone');

        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.accounts.impersonate', [$tenant->account_id, $owner]), ['reason' => 'look'])
            ->assertSessionHas('error');

        // Refused outright, rather than starting a session, auditing it, and
        // bouncing the operator to a lock screen.
        $this->assertAuthenticatedAs($this->admin);
        $this->assertDatabaseMissing('admin_audit_logs', ['action' => 'impersonation.started']);
    }
}
