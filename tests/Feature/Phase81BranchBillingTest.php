<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PriceResolver;
use App\Services\Proration;
use App\Services\StoreClosure;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * REQ-15 — branches become billable, and a bargain becomes recordable.
 *
 * Before this, `quantity` and `billableStores()` both existed and neither ever
 * reached the price: a three-branch customer paid exactly what a one-branch
 * customer paid. This covers the three price concepts and, crucially, that they
 * stay separate:
 *
 *   list rate (volume-tiered) → negotiated rate (per branch) → one-off bargain
 */
class Phase81BranchBillingTest extends TestCase
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

    private function tenant(string $name, ?Account $account = null): Tenant
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        return app(StoreProvisioner::class)->provision($owner, ['store_name' => $name], $account);
    }

    /** A paying customer whose period runs from 10 days ago to 20 days ahead. */
    private function payingCustomer(string $name = 'Alpha Optical'): Subscription
    {
        $tenant = $this->tenant($name);
        $subscription = $tenant->account->subscription;

        $subscription->forceFill([
            'status' => 'active',
            'interval' => 'monthly',
            'quantity' => 1,
            'current_period_end' => Carbon::today(config('billing.timezone'))->addDays(20),
        ])->save();

        return $subscription->fresh();
    }

    private function basic(): Plan
    {
        return Plan::where('code', 'basic')->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | B1 — quantity finally reaches the price
    |--------------------------------------------------------------------------
    */

    public function test_price_scales_with_branch_count(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update(['monthly_price' => 500, 'price_tiers' => null]);
        $subscription = $subscription->fresh();

        $resolver = app(PriceResolver::class);

        // The defect this whole requirement exists for: these used to be equal.
        $this->assertEquals(500.0, $resolver->effectivePrice($subscription, 'monthly', 1));
        $this->assertEquals(1000.0, $resolver->effectivePrice($subscription, 'monthly', 2));
        $this->assertEquals(1500.0, $resolver->effectivePrice($subscription, 'monthly', 3));
    }

    public function test_volume_tiers_apply_to_every_branch_not_just_the_extra_ones(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update([
            'monthly_price' => 500,
            'price_tiers' => [
                ['from' => 1, 'to' => 2, 'monthly' => 500],
                ['from' => 3, 'to' => 4, 'monthly' => 450],
                ['from' => 5, 'to' => null, 'monthly' => 400],
            ],
        ]);
        $subscription = $subscription->fresh();
        $resolver = app(PriceResolver::class);

        // Volume, not graduated: 5 branches are 5 × 400, not 2×500 + 2×450 + 1×400.
        $this->assertEquals(1000.0, $resolver->effectivePrice($subscription, 'monthly', 2));
        $this->assertEquals(1350.0, $resolver->effectivePrice($subscription, 'monthly', 3));
        $this->assertEquals(2000.0, $resolver->effectivePrice($subscription, 'monthly', 5));
        $this->assertEquals(4000.0, $resolver->effectivePrice($subscription, 'monthly', 10));
    }

    public function test_the_breakdown_names_the_tier_that_was_applied(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update([
            'monthly_price' => 500,
            'price_tiers' => [
                ['from' => 1, 'to' => 2, 'monthly' => 500],
                ['from' => 3, 'to' => null, 'monthly' => 450],
            ],
        ]);

        $breakdown = app(PriceResolver::class)->breakdown($subscription->fresh(), 'monthly', 4);

        // "Which rate did you charge me?" must be answerable from the document.
        $this->assertSame('3+ branches', $breakdown['tier_label']);
        $this->assertEquals(450.0, $breakdown['unit_price']);
        $this->assertEquals(1800.0, $breakdown['effective']);
    }

    public function test_a_tier_missing_a_price_falls_back_rather_than_charging_nothing(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update([
            'monthly_price' => 500,
            'yearly_price' => 5000,
            // Yearly deliberately absent from the tier.
            'price_tiers' => [['from' => 1, 'to' => null, 'monthly' => 400]],
        ]);

        // A missing number must never read as free.
        $this->assertEquals(5000.0, app(PriceResolver::class)
            ->effectivePrice($subscription->fresh(), 'yearly', 1));
    }

    public function test_a_negotiated_price_is_per_branch_and_multiplies(): void
    {
        $subscription = $this->payingCustomer();
        $subscription->forceFill([
            'negotiated_price' => 3500,
            'negotiated_interval' => 'yearly',
            'negotiated_reason' => 'first customer rate',
            'interval' => 'yearly',
        ])->save();

        $resolver = app(PriceResolver::class);

        // Owner decision 2026-08-14. Sahaj at one branch is unchanged — which is
        // why this was safe to deploy — and only moves if he opens a second.
        $this->assertEquals(3500.0, $resolver->effectivePrice($subscription->fresh(), 'yearly', 1));
        $this->assertEquals(7000.0, $resolver->effectivePrice($subscription->fresh(), 'yearly', 2));
    }

    public function test_nobody_is_repriced_by_the_deploy(): void
    {
        // Every existing subscription is quantity 1 with one store and no tiers.
        $subscription = $this->payingCustomer();

        $this->assertSame(1, $subscription->quantity);
        $this->assertNull($this->basic()->price_tiers);
        $this->assertEquals(
            (float) $this->basic()->monthly_price,
            app(PriceResolver::class)->effectivePrice($subscription, 'monthly'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | B2 / B3 — proration, and that it shows its working
    |--------------------------------------------------------------------------
    */

    public function test_adding_a_branch_charges_the_part_period_and_raises_the_count(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update(['monthly_price' => 500, 'price_tiers' => null]);

        $branch = $this->tenant('Alpha — Adajan', $subscription->account);
        $subscription->refresh();

        // Billed for two branches now, from this moment.
        $this->assertSame(2, $subscription->quantity);

        $prorata = SubscriptionInvoice::withoutGlobalScopes()
            ->where('account_id', $subscription->account_id)
            ->where('method', 'adjustment')
            ->firstOrFail();

        $this->assertGreaterThan(0, (float) $prorata->amount);
        // Tagged to the branch that caused it, so "what did Branch 2 cost?"
        // stays answerable.
        $this->assertSame($branch->id, $prorata->tenant_id);
    }

    public function test_the_prorated_charge_stores_its_full_working(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update(['monthly_price' => 500, 'price_tiers' => null]);

        $this->tenant('Alpha — Adajan', $subscription->account);

        $prorata = SubscriptionInvoice::withoutGlobalScopes()
            ->where('method', 'adjustment')->firstOrFail();

        // The owner's requirement: it must show HOW it was calculated.
        $this->assertTrue($prorata->hasCalculation());

        $labels = collect($prorata->calculation['lines'])->pluck('label');
        $this->assertContains('Branch starts', $labels);
        $this->assertContains('Current billing period', $labels);
        $this->assertContains('Days being charged', $labels);
        $this->assertContains('Rate', $labels);

        // And the arithmetic itself, checkable at a glance.
        $this->assertStringContainsString('÷', $prorata->calculation['formula']);
    }

    public function test_the_proration_maths_is_days_remaining_over_days_in_period(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update(['monthly_price' => 500, 'price_tiers' => null]);
        $subscription = $subscription->fresh();

        $result = app(Proration::class)->forNewBranch($subscription);

        $this->assertTrue($result['applicable']);
        $expected = round(500 * $result['days_charged'] / $result['days_in_period'], 2);
        $this->assertEquals($expected, $result['amount']);

        // Actual calendar days, not 30/360 — the convention a shopkeeper can
        // verify on a wall calendar.
        $this->assertSame(20, $result['days_charged']);
    }

    public function test_a_branch_joining_at_a_cheaper_tier_is_prorated_at_that_tier(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update([
            'monthly_price' => 500,
            'price_tiers' => [
                ['from' => 1, 'to' => 1, 'monthly' => 500],
                ['from' => 2, 'to' => null, 'monthly' => 300],
            ],
        ]);

        // Charged at the rate the account lands on WITH this branch, not the
        // one it is leaving.
        $result = app(Proration::class)->forNewBranch($subscription->fresh());
        $this->assertEquals(300.0, $result['unit_price']);
        $this->assertSame('2+ branches', $result['tier_label']);
    }

    public function test_a_trial_customer_is_not_charged_for_a_new_branch(): void
    {
        $tenant = $this->tenant('Trial Shop');          // trialing by default
        $account = $tenant->account;

        $this->tenant('Trial Shop — Branch', $account);

        // Nothing to pro-rate against; they have not paid for anything yet.
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()
            ->where('method', 'adjustment')->count());

        // But the count still rises, or the branch is free forever.
        $this->assertSame(2, $account->subscription->fresh()->quantity);
    }

    public function test_a_comped_customer_is_not_charged_for_a_new_branch(): void
    {
        $subscription = $this->payingCustomer();
        app(SubscriptionLifecycle::class)->commit($subscription, 'comp', [
            'months' => 6, 'reason' => 'on the house',
        ]);

        $this->tenant('Alpha — Branch', $subscription->account);

        $prorata = SubscriptionInvoice::withoutGlobalScopes()->where('method', 'adjustment')->count();
        $this->assertSame(0, $prorata);
    }

    public function test_why_no_charge_is_recorded_rather_than_silently_skipped(): void
    {
        $tenant = $this->tenant('Trial Shop');
        $this->tenant('Trial Shop — Branch', $tenant->account);

        $entry = AdminAuditLog::where('action', 'subscription.branch_added')->firstOrFail();

        // "Nothing to charge" and "we could not work it out" look identical in a
        // number and completely different to an operator deciding whether to chase.
        $this->assertNotNull($entry->meta['why_no_charge']);
        $this->assertStringContainsString('trial', mb_strtolower($entry->meta['why_no_charge']));
    }

    public function test_the_first_store_on_an_account_is_never_prorated(): void
    {
        $this->tenant('Only Shop');

        // It IS the clock — there is nothing to join.
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()
            ->where('method', 'adjustment')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Renewal reconciles the count (D4)
    |--------------------------------------------------------------------------
    */

    public function test_a_closed_branch_stops_being_charged_at_the_next_renewal(): void
    {
        $subscription = $this->payingCustomer();
        $branch = $this->tenant('Alpha — Adajan', $subscription->account);
        $this->assertSame(2, $subscription->fresh()->quantity);

        app(StoreClosure::class)->close($branch, 'shut');

        // D4 — no refund; they keep what they paid for until the cycle ends.
        $this->assertSame(2, $subscription->fresh()->quantity);

        app(SubscriptionLifecycle::class)->commit($subscription->fresh(), 'renew', [
            'amount' => 500, 'method' => 'cash',
        ]);

        $this->assertSame(1, $subscription->fresh()->quantity);
    }

    /*
    |--------------------------------------------------------------------------
    | The one-off bargain — and that it stays one-off
    |--------------------------------------------------------------------------
    */

    public function test_paying_less_than_the_price_records_a_discount(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update(['monthly_price' => 500, 'price_tiers' => null]);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.accounts.payment', $subscription->account_id), [
                'amount' => 400,
                'method' => 'cash',
                'discount_reason' => 'bargained at the counter',
            ])->assertSessionHas('status');

        $invoice = SubscriptionInvoice::withoutGlobalScopes()->latest('id')->firstOrFail();

        // `amount` keeps meaning "what was received", so every revenue figure
        // built on it stays correct untouched.
        $this->assertEquals(400.0, (float) $invoice->amount);
        $this->assertEquals(500.0, (float) $invoice->list_amount);
        $this->assertEquals(100.0, (float) $invoice->discount_amount);
        $this->assertSame('bargained at the counter', $invoice->discount_reason);
    }

    public function test_a_bargain_does_not_change_their_ongoing_price(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update(['monthly_price' => 500, 'price_tiers' => null]);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.accounts.payment', $subscription->account_id), [
                'amount' => 400, 'method' => 'cash', 'discount_reason' => 'one-off',
            ]);

        // THE distinction. A standing lower rate is a negotiated price; this is
        // one charge. Conflating them is how a "one-off" becomes permanent.
        $this->assertEquals(500.0, app(PriceResolver::class)
            ->effectivePrice($subscription->fresh(), 'monthly'));
        $this->assertNull($subscription->fresh()->negotiated_price);
    }

    public function test_paying_the_full_price_records_no_discount_at_all(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update(['monthly_price' => 500, 'price_tiers' => null]);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('superadmin.accounts.payment', $subscription->account_id), [
                'amount' => 500, 'method' => 'cash',
            ]);

        $invoice = SubscriptionInvoice::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertNull($invoice->discount_amount);
        $this->assertNull($invoice->list_amount);
        $this->assertFalse($invoice->hasDiscount());
    }

    public function test_a_renewal_can_be_bargained_too(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update(['monthly_price' => 500, 'price_tiers' => null]);

        app(SubscriptionLifecycle::class)->commit($subscription->fresh(), 'renew', [
            'amount' => 450, 'method' => 'cash', 'discount_reason' => 'bargained at renewal',
        ]);

        $invoice = SubscriptionInvoice::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertEquals(50.0, (float) $invoice->discount_amount);
        $this->assertSame('bargained at renewal', $invoice->discount_reason);
    }

    public function test_a_discount_always_carries_a_reason(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update(['monthly_price' => 500, 'price_tiers' => null]);

        // No explicit discount reason given — it must still end up explained,
        // not blank, because in six months the ledger has to justify itself.
        app(SubscriptionLifecycle::class)->commit($subscription->fresh(), 'renew', [
            'amount' => 450, 'method' => 'cash',
        ]);

        $invoice = SubscriptionInvoice::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertNotEmpty($invoice->discount_reason);
    }

    /*
    |--------------------------------------------------------------------------
    | B6 — the self-serve guard (PR-14)
    |--------------------------------------------------------------------------
    */

    public function test_a_multi_branch_customer_is_supervised_automatically(): void
    {
        $subscription = $this->payingCustomer();
        $account = $subscription->account;

        $this->assertFalse($account->fresh()->isSupervised());

        $this->tenant('Alpha — Adajan', $account);

        // BillingService sends Razorpay a hardcoded quantity of 1, so a
        // multi-branch customer self-serving would pay for one branch. Until
        // PR-14 lands they are billed by hand.
        $this->assertTrue($account->fresh()->isSupervised());
        $this->assertStringContainsString('more than one store', $account->fresh()->supervisionReason());
    }

    public function test_the_guard_actually_closes_their_checkout(): void
    {
        $subscription = $this->payingCustomer();
        $account = $subscription->account;
        $branch = $this->tenant('Alpha — Adajan', $account);

        $owner = User::withoutGlobalScopes()->where('tenant_id', $branch->id)->firstOrFail();

        $this->actingAs($owner)->post(route('tenant.billing.subscribe'))
            ->assertRedirect();

        // Nothing was started at the gateway on their behalf.
        $this->assertNull($subscription->fresh()->razorpay_subscription_id);
    }

    public function test_a_single_branch_customer_can_still_pay_online(): void
    {
        $subscription = $this->payingCustomer();

        // Constraint C1 — self-serve must never be degraded for the customers
        // it already worked for.
        $this->assertFalse($subscription->account->fresh()->isSupervised());
    }

    public function test_closing_a_branch_reopens_self_serve(): void
    {
        $subscription = $this->payingCustomer();
        $account = $subscription->account;
        $branch = $this->tenant('Alpha — Adajan', $account);
        $this->assertTrue($account->fresh()->isSupervised());

        app(StoreClosure::class)->close($branch, 'shut');

        // Back to one billable store, so the guard lifts on its own.
        $this->assertFalse($account->fresh()->isSupervised());
    }

    /*
    |--------------------------------------------------------------------------
    | B4 / B5 — it is visible
    |--------------------------------------------------------------------------
    */

    public function test_the_working_is_shown_to_the_operator_and_the_customer(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update(['monthly_price' => 500, 'price_tiers' => null]);
        $branch = $this->tenant('Alpha — Adajan', $subscription->account);

        // Operator's ledger
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('superadmin.accounts.show', $subscription->account_id))
            ->assertOk()
            ->assertSee('How this was worked out')
            ->assertSee('Days being charged');

        // The customer's own billing page — same partial, same numbers.
        $owner = User::withoutGlobalScopes()->where('tenant_id', $branch->id)->firstOrFail();
        $this->actingAs($owner)->get(route('tenant.billing.index'))
            ->assertOk()
            ->assertSee('How this was worked out');
    }

    public function test_the_receipt_carries_the_working_too(): void
    {
        $subscription = $this->payingCustomer();
        $branch = $this->tenant('Alpha — Adajan', $subscription->account);

        $invoice = SubscriptionInvoice::withoutGlobalScopes()
            ->where('method', 'adjustment')->firstOrFail();

        $html = view('tenant.billing.invoice-pdf', [
            'invoice' => $invoice,
            'tenant' => $branch,
        ])->render();

        // This is the copy they keep, and the one they hold when they ring up.
        $this->assertStringContainsString('How this was worked out', $html);
        $this->assertStringContainsString('Days being charged', $html);
    }

    public function test_a_discount_is_shown_on_the_receipt(): void
    {
        $subscription = $this->payingCustomer();
        $this->basic()->update(['monthly_price' => 500, 'price_tiers' => null]);

        app(SubscriptionLifecycle::class)->commit($subscription->fresh(), 'renew', [
            'amount' => 400, 'method' => 'cash', 'discount_reason' => 'bargained',
        ]);

        $invoice = SubscriptionInvoice::withoutGlobalScopes()->latest('id')->firstOrFail();
        $html = view('tenant.billing.invoice-pdf', [
            'invoice' => $invoice,
            'tenant' => $subscription->account->stores->first(),
        ])->render();

        // They should be able to see what they saved, and we what we gave away.
        $this->assertStringContainsString('Subtotal', $html);
        $this->assertStringContainsString('Discount', $html);
        $this->assertStringContainsString('bargained', $html);
    }

    public function test_the_panel_flags_a_branch_that_is_not_being_billed(): void
    {
        $subscription = $this->payingCustomer();
        $this->tenant('Alpha — Adajan', $subscription->account);

        // Force the drift that should never happen on its own.
        $subscription->fresh()->forceFill(['quantity' => 1])->save();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('superadmin.accounts.show', $subscription->account_id))
            ->assertOk()
            ->assertSee('but has 2');
    }
}
