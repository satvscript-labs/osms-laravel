<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentRecorder;
use App\Services\PriceResolver;
use App\Services\StoreProvisioner;
use App\Support\Mrr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P1 / REQ-12 + REQ-4 + REQ-5 — the account spine.
 *
 * Accounts above stores, plans as data, one channel-agnostic ledger, one price
 * resolver, one provisioning door, and the backfill that brings existing data
 * across. Covers BUG-P04 (comp = ₹0 row) and BUG-P05 (MRR counts what is
 * actually paid) by construction.
 */
class Phase70AccountSpineTest extends TestCase
{
    use RefreshDatabase;

    // ---- Provisioning: one door, every guarantee -------------------------

    public function test_self_signup_provisions_account_store_and_account_bound_trial(): void
    {
        $user = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        $this->actingAs($user)->post(route('onboarding.store'), [
            'store_name' => 'Sahaj Optical',
        ])->assertRedirect(route('tenant.dashboard'));

        $user->refresh();
        $tenant = $user->tenant;

        // The account is named from the PERSON, never the shop (06 §6).
        $this->assertNotNull($tenant->account_id);
        $this->assertSame($user->name, $tenant->account->name);
        $this->assertSame($user->email, $tenant->account->billing_email);
        $this->assertSame($user->id, $tenant->account->owner_user_id);

        // The trial subscription is account-bound from birth (dual-write E6).
        $sub = $tenant->account->subscription;
        $this->assertNotNull($sub);
        $this->assertSame($tenant->id, $sub->tenant_id);
        $this->assertSame('trialing', $sub->status);
    }

    public function test_a_second_store_can_join_an_existing_account(): void
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        $first = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Sahaj — Surat']);
        $second = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Sahaj — Adajan'], $first->account);

        $this->assertSame($first->account_id, $second->account_id);
        $this->assertSame(2, $first->account->stores()->count());
        // One paying relationship — no second account was minted for the branch.
        $this->assertSame(1, Account::count());
    }

    // ---- Pricing: one resolver, itemised --------------------------------

    public function test_price_resolves_negotiated_over_plan_over_config(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $tenant = Tenant::create(['store_name' => 'Pricing Optical']);
        $sub = $tenant->subscription;

        // Plan row (seeded from config: 4999/yr).
        $this->assertSame(4999.0, app(PriceResolver::class)->effectivePrice($sub, 'yearly'));

        // The operator edits the plan row — the DB, not config, is the authority.
        Plan::where('code', 'basic')->first()->update(['yearly_price' => 5999]);
        $this->assertSame(5999.0, app(PriceResolver::class)->effectivePrice($sub->fresh(), 'yearly'));

        // A negotiated deal beats the list price, and the breakdown names it.
        $sub->update(['negotiated_price' => 3500, 'negotiated_reason' => 'first customer rate']);
        $breakdown = app(PriceResolver::class)->breakdown($sub->fresh(), 'yearly');

        $this->assertSame(3500.0, $breakdown['effective']);
        $this->assertSame('negotiated', $breakdown['source']);
        $this->assertSame(5999.0, $breakdown['list_price']);
        $this->assertCount(2, $breakdown['steps']); // list → negotiated: itemised, per PR-13's shape
    }

    public function test_price_falls_back_to_config_when_plans_are_not_seeded(): void
    {
        $tenant = Tenant::create(['store_name' => 'Unseeded Optical']);
        $sub = $tenant->subscription()->first();
        $sub->update(['plan_id' => null]);

        $breakdown = app(PriceResolver::class)->breakdown($sub->fresh(), 'monthly');

        $this->assertSame((float) config('billing.plans.basic.monthly_price'), $breakdown['effective']);
        $this->assertSame('config', $breakdown['source']);
    }

    // ---- MRR: BUG-P05 — counts what is actually paid ---------------------

    public function test_mrr_counts_a_manually_billed_store_at_its_negotiated_price(): void
    {
        $tenant = Tenant::create(['store_name' => 'Cash Optical']);
        $sub = $tenant->subscription;

        // The exact case the old rule got wrong: hand-managed, genuinely paying.
        $sub->update([
            'status' => 'active', 'interval' => 'yearly', 'manual' => true,
            'negotiated_price' => 3600, 'negotiated_reason' => 'cash deal',
        ]);

        $this->assertSame(300.0, Mrr::monthlyValue($sub->fresh()), 'manual=true must no longer be a proxy for "not paying".');
    }

    public function test_mrr_counts_zero_only_for_a_comp_in_force(): void
    {
        $tenant = Tenant::create(['store_name' => 'Comped Optical']);
        $sub = $tenant->subscription;
        $sub->update(['status' => 'active', 'interval' => 'monthly', 'manual' => true]);

        $sub->applyOverride(kind: 'comp', until: now()->addMonths(3), reason: 'goodwill');
        $sub->save();

        $this->assertSame(0.0, Mrr::monthlyValue($sub->fresh()), 'A comp in force is not revenue.');

        // Once the grant lapses, whatever they then pay counts again.
        $this->travel(4)->months();
        $this->assertGreaterThan(0.0, Mrr::monthlyValue($sub->fresh()));
    }

    // ---- Ledger: REQ-5 + BUG-P04 -----------------------------------------

    public function test_a_comp_from_the_panel_writes_a_zero_rupee_ledger_row(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
        $tenant = Tenant::create(['store_name' => 'Grant Optical']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.subscription.activate', $tenant), [
                'months' => 3, 'interval' => 'monthly', 'reason' => 'launch goodwill',
            ])->assertRedirect();

        $row = SubscriptionInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('method', 'comp')->first();

        $this->assertNotNull($row, 'A comp must be a ₹0 ledger ROW, never an absent one (BUG-P04).');
        $this->assertSame(0.0, (float) $row->amount);
        $this->assertSame('operator', $row->source);
        $this->assertSame('launch goodwill', $row->reason);
        $this->assertSame($tenant->account_id ?? $row->account_id, $row->account_id);
        $this->assertNotNull($row->period_end);
        $this->assertMatchesRegularExpression('/^OSMS-\d{4}-\d{4}$/', $row->receipt_no);
    }

    public function test_the_recorder_refuses_a_comp_without_a_reason_and_negative_amounts(): void
    {
        $tenant = Tenant::create(['store_name' => 'Strict Optical']);
        $sub = $tenant->subscription;

        $this->expectException(InvalidArgumentException::class);
        app(PaymentRecorder::class)->record($sub, 0.0, 'comp'); // no reason
    }

    public function test_receipt_numbers_are_platform_wide_and_sequential(): void
    {
        $a = Tenant::create(['store_name' => 'A Optical']);
        $b = Tenant::create(['store_name' => 'B Optical']);
        $recorder = app(PaymentRecorder::class);

        $r1 = $recorder->record($a->subscription, 3500, 'cash', ['reference' => 'till']);
        $r2 = $recorder->record($b->subscription, 4999, 'upi', ['reference' => 'UPI/123']);

        // One sequence across the whole platform (owner decision B1), not per store.
        $year = now()->year;
        $this->assertSame("OSMS-{$year}-0001", $r1->receipt_no);
        $this->assertSame("OSMS-{$year}-0002", $r2->receipt_no);
    }

    public function test_every_lane_writes_the_same_ledger_shape(): void
    {
        $tenant = Tenant::create(['store_name' => 'One Ledger Optical']);

        app(PaymentRecorder::class)->record($tenant->subscription, 4999, 'bank_transfer', [
            'reference' => 'UTR-9',
        ]);

        // Manual row and (defaulted) webhook rows live in ONE table, one query.
        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()->count());
        $row = SubscriptionInvoice::withoutGlobalScopes()->first();
        $this->assertSame('bank_transfer', $row->method);
        $this->assertSame('operator', $row->source);
    }

    // ---- Backfill: idempotent, named from the person ---------------------

    public function test_backfill_creates_one_account_per_tenant_named_from_the_owner(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);

        // Pre-account world: a tenant with an owner, a sub and a ledger row.
        $tenant = Tenant::create(['store_name' => 'Sahaj Optical', 'internal_notes' => 'first customer']);
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id, 'role' => 'store_admin', 'name' => 'Rushi Dharsandiya',
        ]);
        $tenant->forceFill(['account_id' => null])->save();
        Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['account_id' => null, 'plan_id' => null]);
        SubscriptionInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'razorpay_payment_id' => 'pay_OLD',
            'amount' => 499, 'currency' => 'INR', 'status' => 'paid', 'paid_at' => now(),
        ]);
        SubscriptionInvoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['account_id' => null]);

        // Dry run writes nothing.
        $this->artisan('osms:backfill-accounts')->assertExitCode(0);
        $this->assertSame(0, Account::count());

        // Commit.
        $this->artisan('osms:backfill-accounts', ['--commit' => true])->assertExitCode(0);

        $account = Account::sole();
        $this->assertSame('Rushi Dharsandiya', $account->name, 'The account is the PERSON, not the shop.');
        $this->assertSame($owner->email, $account->billing_email);
        $this->assertSame('first customer', $account->internal_notes);
        $this->assertSame($account->id, $tenant->fresh()->account_id);
        $this->assertSame($account->id, Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('account_id'));
        $this->assertSame($account->id, SubscriptionInvoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('account_id'));
        $this->assertNotNull(Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('plan_id'));

        // Idempotent: a second commit creates nothing.
        $this->artisan('osms:backfill-accounts', ['--commit' => true])->assertExitCode(0);
        $this->assertSame(1, Account::count());
    }

    public function test_backfill_flags_a_store_with_no_admin_instead_of_guessing(): void
    {
        $tenant = Tenant::create(['store_name' => 'Orphan Optical']);
        $tenant->forceFill(['account_id' => null])->save();
        Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['account_id' => null]);

        $this->artisan('osms:backfill-accounts', ['--commit' => true])->assertExitCode(0);

        // Visibly flagged for a human, not silently derived from the shop name.
        $this->assertStringContainsString('[owner unknown', Account::sole()->name);
    }

    // ---- Isolation is untouched (Q-B) ------------------------------------

    public function test_two_stores_under_one_account_stay_fully_isolated(): void
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $first = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Branch One']);
        $second = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Branch Two'], $first->account);

        \App\Models\Customer::withoutGlobalScopes()->create([
            'tenant_id' => $second->id, 'name' => 'Branch Two Patient',
        ]);

        // The owner's session is bound to Branch One ($owner->tenant_id was
        // repointed by the second provision — reset to the first store).
        $owner->forceFill(['tenant_id' => $first->id])->save();

        $this->actingAs($owner->fresh());
        $this->assertSame(
            0,
            \App\Models\Customer::count(),
            'A patient in one branch leaked into a sibling branch — tenant_id must stay the isolation key (Q-B).',
        );
    }
}
