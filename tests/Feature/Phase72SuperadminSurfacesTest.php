<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentRecorder;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2 — the read-only operator surfaces, account-first.
 *
 * Covers the IA (Customers and Stores are separate surfaces answering
 * different questions), the Customer 360, the one ledger, and BUG-P09:
 * everything paginates and aggregates in SQL, with no query-per-row.
 */
class Phase72SuperadminSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->admin = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
    }

    /** A fully-formed account: an owner, a store, a subscription. */
    private function makeAccount(string $person, string $store): Tenant
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin', 'name' => $person]);

        return app(StoreProvisioner::class)->provision($owner, ['store_name' => $store]);
    }

    // ---- The surfaces render -------------------------------------------

    public function test_every_p2_surface_renders_for_the_operator(): void
    {
        $tenant = $this->makeAccount('Rushi Dharsandiya', 'Sahaj Optical');

        $this->actingAs($this->admin);

        $this->get(route('superadmin.dashboard'))->assertOk()->assertSee('Today');
        $this->get(route('superadmin.accounts.index'))->assertOk()->assertSee('Customers');
        $this->get(route('superadmin.accounts.show', $tenant->account))->assertOk()->assertSee('Rushi Dharsandiya');
        $this->get(route('superadmin.stores.index'))->assertOk()->assertSee('Sahaj Optical');
        $this->get(route('superadmin.stores.show', $tenant))->assertOk()->assertSee('Sahaj Optical');
        $this->get(route('superadmin.billing.index'))->assertOk()->assertSee('Billing');
        $this->get(route('superadmin.plans.index'))->assertOk()->assertSee('Basic');
        $this->get(route('superadmin.audit.index'))->assertOk();
    }

    public function test_the_legacy_store_screens_stay_reachable_by_url(): void
    {
        // Decision E3 / playbook §4: superseded surfaces leave the nav but stay
        // reachable until the new ones cover every job they did.
        $tenant = $this->makeAccount('Legacy Owner', 'Legacy Optical');

        $this->actingAs($this->admin)
            ->get(route('superadmin.tenants.index'))->assertOk();
        $this->actingAs($this->admin)
            ->get(route('superadmin.tenants.show', $tenant))->assertOk();
    }

    public function test_the_nav_shows_customers_and_stores_but_not_the_legacy_screen(): void
    {
        $res = $this->actingAs($this->admin)->get(route('superadmin.dashboard'));

        $res->assertSee(route('superadmin.accounts.index'));
        $res->assertSee(route('superadmin.stores.index'));
        // Never two nav entries for the same job.
        $res->assertDontSee(route('superadmin.tenants.index'));
    }

    // ---- Customers list: the daily surface -------------------------------

    public function test_the_customers_list_shows_one_row_per_account_not_per_store(): void
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin', 'name' => 'Rushi']);
        $first = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Branch One']);
        app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Branch Two'], $first->account);

        $res = $this->actingAs($this->admin)->get(route('superadmin.accounts.index', ['filter' => 'all']));

        $res->assertOk();
        // One account row, carrying a count of 2 stores — not two customer rows.
        $this->assertCount(1, $res->viewData('rows'));
        $this->assertSame(2, $res->viewData('rows')->first()['stores']);
    }

    public function test_the_customers_list_filters_and_searches(): void
    {
        $this->makeAccount('Alpha Owner', 'Alpha Optical');
        $beta = $this->makeAccount('Beta Owner', 'Beta Optical');
        $beta->account->subscription->update(['status' => 'active']);

        $this->actingAs($this->admin);

        $paid = $this->get(route('superadmin.accounts.index', ['filter' => 'paid']));
        $this->assertCount(1, $paid->viewData('rows'));
        $this->assertSame('Beta Owner', $paid->viewData('rows')->first()['name']);

        // Search reaches the store name too — operators think in shop names.
        $found = $this->get(route('superadmin.accounts.index', ['q' => 'Alpha Optical', 'filter' => 'all']));
        $this->assertCount(1, $found->viewData('rows'));
        $this->assertSame('Alpha Owner', $found->viewData('rows')->first()['name']);
    }

    public function test_the_customers_list_serves_live_json_for_the_alpine_search(): void
    {
        $this->makeAccount('Json Owner', 'Json Optical');

        $res = $this->actingAs($this->admin)
            ->getJson(route('superadmin.accounts.index', ['q' => 'Json', 'filter' => 'all']));

        $res->assertOk()->assertJsonStructure(['rows' => [['id', 'name', 'stores', 'status', 'url']], 'total', 'has_more']);
        $this->assertSame('Json Owner', $res->json('rows.0.name'));
    }

    public function test_the_worklist_surfaces_a_lapsing_trial_and_hides_a_healthy_one(): void
    {
        $soon = $this->makeAccount('Expiring Owner', 'Expiring Optical');
        $soon->account->subscription->update(['current_period_end' => now()->addDays(3)]);

        $healthy = $this->makeAccount('Healthy Owner', 'Healthy Optical');
        $healthy->account->subscription->update(['status' => 'active', 'current_period_end' => now()->addMonths(6)]);

        $res = $this->actingAs($this->admin)->get(route('superadmin.dashboard'));

        $names = collect($res->viewData('worklist'))->pluck('account');
        $this->assertTrue($names->contains('Expiring Owner'));
        $this->assertFalse($names->contains('Healthy Owner'), 'A store renewing in 6 months does not need attention today.');
    }

    // ---- Customer 360 ----------------------------------------------------

    public function test_the_customer_360_shows_stores_price_breakdown_and_ledger(): void
    {
        $tenant = $this->makeAccount('Rushi Dharsandiya', 'Sahaj Optical');
        $sub = $tenant->account->subscription;
        $sub->update(['status' => 'active', 'interval' => 'yearly', 'negotiated_price' => 3500, 'negotiated_reason' => 'first customer rate']);

        app(PaymentRecorder::class)->record($sub->fresh(), 3500, 'cash', ['reference' => 'till-1']);

        $res = $this->actingAs($this->admin)->get(route('superadmin.accounts.show', $tenant->account));

        $res->assertOk()
            ->assertSee('Sahaj Optical')          // stores tab
            ->assertSee('first customer rate')     // bespoke reason
            ->assertSee('till-1')                  // ledger reference
            ->assertSee('bespoke');                // ⚑ badge

        // The breakdown comes from the same resolver a charge would use.
        $this->assertSame(3500.0, $res->viewData('price')['effective']);
        $this->assertSame(3500.0, $res->viewData('lifetime'));
    }

    public function test_the_360_surfaces_an_operator_override_as_a_visible_badge(): void
    {
        $tenant = $this->makeAccount('Comped Owner', 'Comped Optical');
        $sub = $tenant->account->subscription;
        $sub->applyOverride(kind: 'comp', until: now()->addMonths(6), reason: 'launch goodwill');
        $sub->save();

        // Playbook §5.2 rule 6 — overrides are visible, not hidden.
        $this->actingAs($this->admin)
            ->get(route('superadmin.accounts.show', $tenant->account))
            ->assertOk()
            ->assertSee('override in force')
            ->assertSee('launch goodwill');
    }

    // ---- Stores surface --------------------------------------------------

    public function test_the_stores_surface_links_each_store_back_to_its_payer(): void
    {
        $tenant = $this->makeAccount('Rushi Dharsandiya', 'Sahaj Optical');

        $this->actingAs($this->admin)
            ->get(route('superadmin.stores.show', $tenant))
            ->assertOk()
            ->assertSee('Rushi Dharsandiya')
            ->assertSee(route('superadmin.accounts.show', $tenant->account));
    }

    public function test_a_store_with_no_account_is_visibly_flagged(): void
    {
        // Only possible before the P1 backfill runs — a half-migrated deploy
        // must be visible, never silent.
        $tenant = Tenant::create(['store_name' => 'Orphan Optical']);
        $tenant->forceFill(['account_id' => null])->save();

        $this->actingAs($this->admin)
            ->get(route('superadmin.stores.index'))
            ->assertOk()
            ->assertSee('No customer linked');

        $this->actingAs($this->admin)
            ->get(route('superadmin.dashboard'))
            ->assertOk()
            ->assertSee('not yet linked to a customer');
    }

    // ---- Billing ledger --------------------------------------------------

    public function test_the_billing_ledger_shows_every_channel_in_one_place(): void
    {
        $a = $this->makeAccount('Cash Payer', 'Cash Optical');
        $b = $this->makeAccount('Comped Payer', 'Comp Optical');

        $recorder = app(PaymentRecorder::class);
        $recorder->record($a->account->subscription, 4999, 'cash', ['reference' => 'till-9']);
        $recorder->record($b->account->subscription, 0, 'comp', ['reason' => 'goodwill']);

        $res = $this->actingAs($this->admin)->get(route('superadmin.billing.index'));

        $res->assertOk()->assertSee('Cash')->assertSee('Complimentary')->assertSee('till-9');

        // A ₹0 comp is a row, but never counts as collected revenue.
        $this->assertSame(4999.0, $res->viewData('totals')['collected']);
        $this->assertSame(1, $res->viewData('totals')['comped']);
    }

    public function test_a_reversed_payment_is_excluded_from_collected_revenue(): void
    {
        $tenant = $this->makeAccount('Reversal Payer', 'Reversal Optical');
        $row = app(PaymentRecorder::class)->record($tenant->account->subscription, 4999, 'upi');

        $row->update(['reversed_at' => now(), 'reversal_reason' => 'duplicate']);

        $res = $this->actingAs($this->admin)->get(route('superadmin.billing.index'));

        // A reversal is a state, never a delete: the row is still visible…
        $res->assertSee('Reversed');
        // …but the money is not counted.
        $this->assertSame(0.0, $res->viewData('totals')['collected']);
    }

    // ---- BUG-P09: SQL pagination + no N+1 --------------------------------

    public function test_the_customers_list_paginates_in_sql_and_does_not_scale_queries_with_rows(): void
    {
        foreach (range(1, 30) as $i) {
            $this->makeAccount("Owner {$i}", "Store {$i}");
        }

        $this->actingAs($this->admin);

        DB::enableQueryLog();
        $res = $this->get(route('superadmin.accounts.index', ['filter' => 'all']));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $res->assertOk();

        // Paginated: 25 per page, not all 30 (BUG-P09 — the old list loaded
        // every tenant into memory with no pagination at all).
        $this->assertCount(25, $res->viewData('rows'));
        $this->assertSame(30, $res->viewData('accounts')->total());

        // And the query count must not scale with the row count. The old
        // version ran one query PER STORE inside a map.
        $this->assertLessThan(
            15,
            $queries,
            "The customers list ran {$queries} queries for 25 rows — that is an N+1.",
        );
    }

    public function test_the_dashboard_does_not_scale_queries_with_the_number_of_accounts(): void
    {
        foreach (range(1, 20) as $i) {
            $t = $this->makeAccount("Dash Owner {$i}", "Dash Store {$i}");
            $t->account->subscription->update(['status' => 'active']);
        }

        $this->actingAs($this->admin);

        DB::enableQueryLog();
        $this->get(route('superadmin.dashboard'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            25,
            $queries,
            "Today ran {$queries} queries for 20 accounts — aggregates must happen in SQL.",
        );
    }

    // ---- Plans -----------------------------------------------------------

    public function test_the_plans_surface_lists_prices_and_who_is_bespoke(): void
    {
        $tenant = $this->makeAccount('Bespoke Owner', 'Bespoke Optical');
        $tenant->account->subscription->update([
            'negotiated_price' => 3500,
            'negotiated_reason' => 'launch deal',
            'interval' => 'yearly',
        ]);

        $res = $this->actingAs($this->admin)->get(route('superadmin.plans.index'));

        $res->assertOk()
            ->assertSee('Basic')
            ->assertSee('Bespoke Owner')
            ->assertSee('launch deal');

        $this->assertCount(1, $res->viewData('bespoke'));
    }

    public function test_plan_prices_come_from_the_database_not_config(): void
    {
        Plan::where('code', 'basic')->first()->update(['monthly_price' => 777]);

        $this->actingAs($this->admin)
            ->get(route('superadmin.plans.index'))
            ->assertOk()
            ->assertSee('777');
    }
}
