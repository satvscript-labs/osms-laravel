<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use App\Support\Metrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P6 — metrics & polish.
 *
 * The metrics half is governed by playbook §9: *"if the underlying data cannot
 * support a metric honestly, do not display a fake one."* Most of these tests
 * therefore assert RESTRAINT — that a number is absent, or labelled, or scoped —
 * which is the part of an honest dashboard that silently rots first.
 */
class Phase77MetricsAndPolishTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->admin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
    }

    private function asOperator(): self
    {
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);

        return $this;
    }

    private function store(string $name = 'Sahaj Optical'): Tenant
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);

        return app(StoreProvisioner::class)->provision($owner, ['store_name' => $name]);
    }

    /** A paying customer at a known price. */
    private function paying(string $name, float $monthly = 499): Subscription
    {
        $tenant = $this->store($name);
        $subscription = $tenant->account->subscription;

        $subscription->forceFill([
            'status' => 'active',
            'interval' => 'monthly',
            'negotiated_price' => $monthly,
            'negotiated_interval' => 'monthly',
            'negotiated_reason' => 'test',
            'current_period_end' => now()->addDays(20),
        ])->save();

        return $subscription->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Churn — measurable at last, and honest about what it cannot see
    |--------------------------------------------------------------------------
    */

    public function test_churn_is_stamped_by_the_model_whichever_lane_cancels(): void
    {
        $subscription = $this->paying('Leaver', 800);

        // Not through the panel — straight at the model, which is the point:
        // the webhook and the nightly reconcile write this way too.
        $subscription->forceFill(['status' => 'canceled'])->save();

        $subscription->refresh();
        $this->assertNotNull($subscription->churned_at);
        $this->assertSame('active', $subscription->churned_from);

        // Valued at what they were paying BEFORE the status moved. Asking
        // afterwards returns 0 for everyone, which would record every churn as
        // worthless.
        $this->assertEquals(800.0, (float) $subscription->churned_mrr);
    }

    public function test_a_customer_who_comes_back_is_not_counted_as_churned(): void
    {
        $subscription = $this->paying('Returner', 500);
        $subscription->forceFill(['status' => 'canceled'])->save();
        $this->assertNotNull($subscription->fresh()->churned_at);

        $subscription->forceFill(['status' => 'active'])->save();

        // Leaving the stamp would double-count them the next time they leave.
        $subscription->refresh();
        $this->assertNull($subscription->churned_at);
        $this->assertNull($subscription->churned_mrr);
    }

    public function test_a_lapsed_trial_is_not_counted_as_customer_churn(): void
    {
        $trial = $this->store('Never Paid')->account->subscription;
        $trial->forceFill(['status' => 'canceled'])->save();

        $churn = app(Metrics::class)->churn();

        // It never paid, so losing it is a different question — one §8 defers
        // for want of volume. Merging the two would flatter or damn the number
        // depending on the month.
        $this->assertSame(0, $churn['logo']);
        $this->assertEquals(0.0, $churn['revenue']);
        $this->assertSame(1, $churn['trials_lapsed']);
    }

    public function test_churn_ignores_departures_older_than_the_window(): void
    {
        $old = $this->paying('Long Gone', 300);
        $old->forceFill(['status' => 'canceled'])->save();
        $old->forceFill(['churned_at' => now()->subDays(200)])->save();

        $recent = $this->paying('Just Left', 700);
        $recent->forceFill(['status' => 'canceled'])->save();

        $churn = app(Metrics::class)->churn();

        $this->assertSame(1, $churn['logo']);
        $this->assertEquals(700.0, $churn['revenue']);
    }

    public function test_cancellations_predating_tracking_are_declared_not_hidden(): void
    {
        $subscription = $this->paying('Pre-Tracking', 400);
        $subscription->forceFill(['status' => 'canceled'])->save();
        // Exactly what a row cancelled before this migration looks like.
        $subscription->forceFill(['churned_at' => null, 'churned_mrr' => null, 'churned_from' => null])->save();

        $churn = app(Metrics::class)->churn();

        $this->assertSame(0, $churn['logo']);
        $this->assertSame(1, $churn['untracked'], 'silently excluding these would make churn look better than it was');

        $this->asOperator()->get(route('superadmin.dashboard'))
            ->assertOk()
            ->assertSee('predates churn tracking', false);
    }

    public function test_no_churn_percentage_is_shown_below_the_floor(): void
    {
        $this->paying('Only Payer');

        $churn = app(Metrics::class)->churn();
        $this->assertFalse($churn['show_percentage']);

        // §8: "at n=1 a single churn = 100%". The dashboard must say why it is
        // withholding a rate, not just omit it.
        $this->asOperator()->get(route('superadmin.dashboard'))
            ->assertOk()
            ->assertSee('No rate is shown below', false);
    }

    public function test_the_percentage_floor_opens_once_there_are_enough_customers(): void
    {
        for ($i = 0; $i < Metrics::PERCENTAGE_FLOOR; $i++) {
            $this->paying("Payer {$i}");
        }

        $this->assertTrue(app(Metrics::class)->churn()['show_percentage']);
    }

    /*
    |--------------------------------------------------------------------------
    | Collection health
    |--------------------------------------------------------------------------
    */

    public function test_overdue_is_valued_at_the_full_cycle_price_not_a_monthly_slice(): void
    {
        $yearly = $this->paying('Annual Customer');
        $yearly->forceFill([
            'status' => 'past_due',
            'interval' => 'yearly',
            'negotiated_price' => 3500,
            'negotiated_interval' => 'yearly',
        ])->save();

        $collection = app(Metrics::class)->collection();

        $this->assertSame(1, $collection['overdue_count']);
        // ₹292/month would understate the collection problem twelvefold.
        $this->assertEquals(3500.0, $collection['overdue_amount']);
    }

    public function test_a_comped_customer_is_never_expected_to_pay(): void
    {
        $comped = $this->paying('Friend Of The House', 999);

        app(SubscriptionLifecycle::class)->commit($comped, 'comp', [
            'months' => 6,
            'reason' => 'first customer, on the house',
        ]);

        $comped->refresh()->forceFill(['status' => 'past_due'])->save();

        // Expecting money from someone you gave the product to would overstate
        // collections by exactly the value of your own goodwill.
        $this->assertEquals(0.0, app(Metrics::class)->collection()['overdue_amount']);
    }

    public function test_expected_soon_covers_only_the_horizon(): void
    {
        $soon = $this->paying('Renews Soon', 500);
        $soon->forceFill(['current_period_end' => now()->addDays(10)])->save();

        $later = $this->paying('Renews Later', 900);
        $later->forceFill(['current_period_end' => now()->addDays(200)])->save();

        $collection = app(Metrics::class)->collection();

        $this->assertSame(1, $collection['due_soon_count']);
        $this->assertEquals(500.0, $collection['due_soon_amount']);
    }

    public function test_the_dashboard_calls_it_expected_rather_than_outstanding(): void
    {
        $this->paying('Somebody');

        // OSMS raises no invoices in advance, so there is no receivable. Saying
        // "outstanding" would invite the number to be read as a debtors' book.
        $this->asOperator()->get(route('superadmin.dashboard'))
            ->assertOk()
            ->assertSee('Expected in 30 days')
            ->assertSee('not a bill anyone has sent', false)
            ->assertDontSee('Outstanding');
    }

    public function test_the_dashboard_still_does_not_scale_queries_with_customers(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->paying("Customer {$i}");
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->asOperator()->get(route('superadmin.dashboard'))->assertOk();
        $first = count(\Illuminate\Support\Facades\DB::getQueryLog());

        // Disable, not just flush: seeding the second batch would otherwise be
        // logged too and the guard would measure its own fixture.
        \Illuminate\Support\Facades\DB::disableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        for ($i = 12; $i < 24; $i++) {
            $this->paying("Customer {$i}");
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->asOperator()->get(route('superadmin.dashboard'))->assertOk();
        $second = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // The new metrics hydrate bounded sets (overdue, renewing soon), never
        // one query per customer — BUG-P09's guard, extended to P6's additions.
        $this->assertLessThanOrEqual($first + 2, $second,
            "query count grew from {$first} to {$second} when customers doubled");
    }

    /*
    |--------------------------------------------------------------------------
    | Polish — the motion standard, enforced where it can be
    |--------------------------------------------------------------------------
    */

    public function test_every_operator_list_filters_live_over_json(): void
    {
        $this->paying('Searchable Customer');
        SubscriptionInvoice::withoutGlobalScopes()->getQuery();

        foreach ([
            'superadmin.accounts.index',
            'superadmin.stores.index',
            'superadmin.billing.index',
        ] as $route) {
            $response = $this->asOperator()
                ->getJson(route($route, ['q' => 'Searchable']))
                ->assertOk();

            // The contract the shared superadminList() engine depends on.
            $this->assertArrayHasKey('rows', $response->json(), "{$route} must serve live rows");
            $this->assertArrayHasKey('total', $response->json());
        }
    }

    public function test_no_operator_list_still_submits_a_search_form(): void
    {
        $this->paying('Somebody');

        foreach ([
            'superadmin.accounts.index',
            'superadmin.stores.index',
            'superadmin.billing.index',
        ] as $route) {
            $html = $this->asOperator()->get(route($route))->assertOk()->getContent();

            // CLAUDE.md: "Full-page GET reloads for search are not acceptable."
            $this->assertStringNotContainsString('<form method="GET"', $html,
                "{$route} still reloads the page to search");
            $this->assertStringContainsString('superadminList(', $html,
                "{$route} must use the one shared live-list engine");
        }
    }

    public function test_operator_overlays_are_bottom_sheets_on_phones(): void
    {
        $tenant = $this->store();

        // 03 §6.2 predicted this retrofit would be one change because every
        // action renders through one component. The marker proves they do.
        $this->asOperator()->get(route('superadmin.accounts.show', $tenant->account_id))
            ->assertOk()
            ->assertSee('operator-sheet', false);
    }

    public function test_the_panel_carries_no_inline_font_size_literals(): void
    {
        $tenant = $this->store();

        foreach ([
            route('superadmin.dashboard'),
            route('superadmin.accounts.index'),
            route('superadmin.accounts.show', $tenant->account_id),
            route('superadmin.stores.index'),
            route('superadmin.billing.index'),
        ] as $url) {
            $html = $this->asOperator()->get($url)->assertOk()->getContent();

            // The design directive: never a hardcoded font-size in a Blade view.
            // Token references (font-size:var(--…)) are the correct form.
            $this->assertDoesNotMatchRegularExpression(
                '/font-size:\s*[0-9]/', $html,
                "{$url} contains a literal font-size — add a token or class instead",
            );
        }
    }

    public function test_the_audit_trail_links_to_the_current_store_surface(): void
    {
        $tenant = $this->store();
        \App\Models\AdminAuditLog::create([
            'admin_user_id' => $this->admin->id,
            'admin_email' => $this->admin->email,
            'action' => 'test.entry',
            'tenant_id' => $tenant->id,
            'description' => 'Something happened',
        ]);

        $this->asOperator()->get(route('superadmin.audit.index'))
            ->assertOk()
            ->assertSee(route('superadmin.stores.show', $tenant), false)
            ->assertDontSee(route('superadmin.tenants.show', $tenant), false);
    }

    public function test_plans_are_still_the_pricing_authority(): void
    {
        // A guard on the metrics, not on plans: every rupee above is resolved
        // through PriceResolver, so a plan price change must move them.
        Plan::where('code', 'basic')->update(['monthly_price' => 777]);

        $tenant = $this->store();
        $subscription = $tenant->account->subscription;
        $subscription->forceFill([
            'status' => 'past_due',
            'interval' => 'monthly',
            'plan_id' => Plan::where('code', 'basic')->value('id'),
        ])->save();

        $this->assertEquals(777.0, app(Metrics::class)->collection()['overdue_amount']);
    }
}
