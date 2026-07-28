<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PriceResolver;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use App\Support\Mrr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the P0–P3 audit (_artifacts/platform/91_P0_P3_AUDIT.md).
 *
 * Every test here FAILED before its fix. They exist so the same class of
 * mistake cannot return quietly — several of these were invisible by
 * inspection and only surfaced by running the code.
 */
class Phase74AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->admin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
    }

    /** @return array{0: Tenant, 1: Tenant} first store, second branch */
    private function twoBranches(): array
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin', 'name' => 'Rushi']);
        $a = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Branch A']);
        $b = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Branch B'], $a->account);

        return [$a, $b];
    }

    // ================================================================
    // AUD-01 — MRR must not count lapsed customers
    // ================================================================

    public function test_aud01_a_lapsed_subscription_contributes_nothing_to_mrr(): void
    {
        $tenant = Tenant::create(['store_name' => 'Lapsed Optical']);
        $sub = $tenant->subscription;

        // Genuinely paid, then simply stopped. Nothing ever moved it off
        // `active`, so it used to count toward MRR forever.
        $sub->update(['status' => 'active', 'interval' => 'monthly', 'current_period_end' => now()->subMonths(6)]);

        $this->assertSame('locked', $sub->fresh()->accessState(), 'Sanity: access is already cut.');
        $this->assertSame(0.0, Mrr::monthlyValue($sub->fresh()), 'A customer past their period end is not revenue.');
    }

    public function test_aud01_the_dashboard_does_not_report_lapsed_customers_as_paying_revenue(): void
    {
        $tenant = Tenant::create(['store_name' => 'Lapsed Optical']);
        $tenant->subscription->update([
            'status' => 'active', 'interval' => 'monthly', 'current_period_end' => now()->subMonths(6),
        ]);

        $res = $this->actingAs($this->admin)->get(route('superadmin.dashboard'));

        $this->assertSame(0.0, $res->viewData('stats')['mrr']);
        $this->assertSame(0.0, $res->viewData('stats')['arr']);
    }

    public function test_aud01_the_daily_reconcile_lapses_paid_subscriptions(): void
    {
        $tenant = Tenant::create(['store_name' => 'Churning Optical']);
        $sub = $tenant->subscription;

        // Past the period AND past the grace window → no longer merely late.
        $sub->update(['status' => 'active', 'current_period_end' => now()->subDays(20)]);

        $this->artisan('subscriptions:reconcile')->assertSuccessful();
        $this->assertSame('past_due', $sub->fresh()->status);

        // Still unpaid 30 days after grace ended → churn.
        $sub->update(['current_period_end' => now()->subDays(60)]);
        $this->artisan('subscriptions:reconcile')->assertSuccessful();
        $this->assertSame('canceled', $sub->fresh()->status);
    }

    public function test_aud01_the_reconcile_never_overrules_an_operator(): void
    {
        $tenant = Tenant::create(['store_name' => 'Comped Optical']);
        $sub = $tenant->subscription;
        $sub->update(['status' => 'active', 'current_period_end' => now()->subDays(60)]);
        $sub->applyOverride('comp', now()->addYear(), 'launch partner');
        $sub->save();

        $this->artisan('subscriptions:reconcile')->assertSuccessful();

        // Playbook §5.2 rule 2 — reconciliation reconciles; it never clobbers
        // a human decision.
        $this->assertSame('active', $sub->fresh()->status);
    }

    // ================================================================
    // AUD-02 — tenant surfaces must resolve via the ACCOUNT
    // ================================================================

    public function test_aud02_a_second_branch_sees_its_billing_page(): void
    {
        [$a, $b] = $this->twoBranches();
        $b->account->subscription->update(['status' => 'active', 'current_period_end' => now()->addYear()]);

        $ownerOfB = User::factory()->create(['tenant_id' => $b->id, 'role' => 'store_admin']);

        $res = $this->actingAs($ownerOfB)->get(route('tenant.billing.index'));

        $res->assertOk();
        // Before the fix this was NULL: `Subscription::first()` is tenant-scoped,
        // and a branch has no subscription row of its own.
        $this->assertNotNull($res->viewData('subscription'), 'A branch must see its payer’s subscription.');
        $this->assertSame($b->account->subscription->id, $res->viewData('subscription')->id);
    }

    public function test_aud02_a_second_branch_sees_the_trial_banner(): void
    {
        [$a, $b] = $this->twoBranches();
        $ownerOfB = User::factory()->create(['tenant_id' => $b->id, 'role' => 'store_admin']);

        // The account is on a trial, so every store under it should say so.
        $this->actingAs($ownerOfB)
            ->get(route('tenant.dashboard'))
            ->assertOk()
            ->assertSee('trial', false);
    }

    // ================================================================
    // AUD-03 — reactivate must fully undo a scheduled cancellation
    // ================================================================

    public function test_aud03_reactivate_clears_a_scheduled_cancellation(): void
    {
        [$a] = $this->twoBranches();
        $sub = $a->account->subscription;
        $sub->update(['status' => 'active', 'current_period_end' => now()->addYear()]);

        $this->actingAs($this->admin);
        $lc = app(SubscriptionLifecycle::class);

        $lc->commit($sub->fresh(), 'cancel', ['reason' => 'leaving', 'at_period_end' => true]);
        $this->assertTrue((bool) $sub->fresh()->cancel_at_period_end, 'Sanity: scheduled to cancel.');

        $lc->commit($sub->fresh(), 'reactivate', ['reason' => 'changed their mind']);

        // Previously this stayed true: the operator believed they had undone the
        // cancellation while the store was still queued to lapse.
        $this->assertFalse((bool) $sub->fresh()->cancel_at_period_end);
        $this->assertSame('active', $sub->fresh()->status);
    }

    // ================================================================
    // AUD-04 — a negotiated price belongs to an interval
    // ================================================================

    public function test_aud04_a_negotiated_price_only_applies_at_its_agreed_interval(): void
    {
        $tenant = Tenant::create(['store_name' => 'Bespoke Optical']);
        $sub = $tenant->subscription;
        $sub->update(['interval' => 'yearly', 'negotiated_price' => 3500, 'negotiated_interval' => 'yearly']);

        $r = app(PriceResolver::class);

        $this->assertSame(3500.0, $r->effectivePrice($sub->fresh(), 'yearly'), 'Applies at the agreed interval.');

        // The 12x error: ₹3,500 agreed per YEAR must never be charged per MONTH.
        $monthly = $r->breakdown($sub->fresh(), 'monthly');
        $this->assertSame((float) config('billing.plans.basic.monthly_price'), $monthly['effective']);
        $this->assertTrue($monthly['negotiated_mismatch'], 'The mismatch must be reported, not silent.');
    }

    public function test_aud04_setting_a_price_records_the_interval_it_was_agreed_for(): void
    {
        [$a] = $this->twoBranches();
        $sub = $a->account->subscription;
        $sub->update(['interval' => 'monthly']);

        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.accounts.action', $a->account), [
                'action' => 'set_price', 'price' => 3500, 'interval' => 'yearly', 'reason' => 'annual deal',
            ])->assertSessionHas('status');

        $this->assertSame('yearly', $sub->fresh()->negotiated_interval);
    }

    public function test_aud04_switching_interval_warns_that_a_bespoke_price_stops_applying(): void
    {
        [$a] = $this->twoBranches();
        $sub = $a->account->subscription;
        $sub->update(['interval' => 'yearly', 'negotiated_price' => 3500, 'negotiated_interval' => 'yearly']);

        $res = $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('superadmin.accounts.preview', $a->account), [
                'action' => 'switch_interval', 'interval' => 'monthly',
            ]);

        $res->assertOk();
        $this->assertNotEmpty($res->json('warnings'), 'The operator must be told their deal stops applying.');
    }

    // ================================================================
    // AUD-05 — a ₹0 renewal is a comp, not a cash payment
    // ================================================================

    public function test_aud05_a_zero_amount_renewal_is_refused(): void
    {
        [$a] = $this->twoBranches();

        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.accounts.action', $a->account), [
                'action' => 'renew', 'amount' => 0, 'method' => 'cash', 'interval' => 'monthly',
            ])->assertSessionHas('error');

        // No fictional "₹0 cash payment" on the ledger.
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    public function test_aud05_a_blank_amount_still_falls_back_to_the_list_price(): void
    {
        [$a] = $this->twoBranches();

        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.accounts.action', $a->account), [
                'action' => 'renew', 'amount' => '', 'method' => 'cash', 'interval' => 'monthly',
            ])->assertSessionHas('status');

        $this->assertSame('499.00', SubscriptionInvoice::withoutGlobalScopes()->first()->amount);
    }

    // ================================================================
    // AUD-06 — one decision must not silently erase another
    // ================================================================

    public function test_aud06_comping_over_a_suspension_warns_the_operator(): void
    {
        [$a] = $this->twoBranches();
        $sub = $a->account->subscription;
        $sub->update(['status' => 'active', 'current_period_end' => now()->addYear()]);

        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);
        app(SubscriptionLifecycle::class)->commit($sub->fresh(), 'suspend', ['reason' => 'non-payment']);

        $res = $this->postJson(route('superadmin.accounts.preview', $a->account), [
            'action' => 'comp', 'months' => 1, 'reason' => 'resolved',
        ]);

        $res->assertOk();
        $this->assertNotEmpty($res->json('warnings'));
        $this->assertStringContainsString('suspension', $res->json('warnings.0'));
    }

    // ================================================================
    // AUD-07 / 08 / 09 — hygiene
    // ================================================================

    public function test_aud07_the_legacy_screens_require_a_reason_like_everything_else(): void
    {
        $tenant = Tenant::create(['store_name' => 'Legacy Optical']);

        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.subscription.activate', $tenant), ['months' => 60, 'interval' => 'yearly'])
            ->assertSessionHasErrors('reason');

        // A second, weaker path that could write an override with no reason is
        // exactly the divergence one-service-every-door exists to prevent.
        $this->assertNull($tenant->subscription->fresh()->override_kind);
    }

    public function test_aud08_reactivate_writes_exactly_one_audit_row(): void
    {
        [$a] = $this->twoBranches();
        $sub = $a->account->subscription;
        $sub->update(['status' => 'active', 'current_period_end' => now()->addYear()]);

        $this->actingAs($this->admin);
        $lc = app(SubscriptionLifecycle::class);
        $lc->commit($sub->fresh(), 'suspend', ['reason' => 'non-payment']);

        $before = \App\Models\AdminAuditLog::count();
        $lc->commit($sub->fresh(), 'reactivate', ['reason' => 'they paid']);

        $this->assertSame($before + 1, \App\Models\AdminAuditLog::count(), 'One action, one audit row.');
    }

    public function test_aud09_a_preview_never_500s_on_an_unknown_action(): void
    {
        [$a] = $this->twoBranches();

        $res = $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('superadmin.accounts.preview', $a->account), ['action' => 'not_a_real_action']);

        $res->assertOk();
        $this->assertNotNull($res->json('error'), 'A rejected preview reports; it does not crash.');
    }
}
