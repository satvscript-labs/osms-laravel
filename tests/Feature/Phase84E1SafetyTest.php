<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use App\Services\PlanMissing;
use App\Services\PriceResolver;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E1 — the safety rails around the billing engine (09_E1_PLAN.md).
 *
 *   E1.0  the test suite can never reach the real payment gateway (FB-17)
 *   E1.7  prices come from plan ROWS; no row → refused, never a silent price
 *   E1.11 a money form is acted on once, never twice
 */
class Phase84E1SafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_suite_never_holds_gateway_credentials(): void
    {
        // The developer's .env holds a LIVE Razorpay key; phpunit.xml forces it
        // empty. If this ever fails, an unmocked path would charge real cards.
        $this->assertEmpty(config('services.razorpay.key'));
        $this->assertEmpty(config('services.razorpay.secret'));
        $this->assertEmpty(config('services.razorpay.webhook_secret'));
        $this->assertFalse(app(BillingService::class)->isConfigured());
    }

    // ------------------------------------------------------------------
    // E1.7 — plans are data
    // ------------------------------------------------------------------

    private function unboundSubscription(string $tier): Subscription
    {
        $tenant = Tenant::create(['store_name' => 'Unbound']);
        $sub = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $sub->forceFill(['plan_id' => null, 'tier' => $tier, 'interval' => 'monthly'])->save();

        return $sub->fresh();
    }

    public function test_an_unbound_subscription_is_priced_from_the_plan_row_matching_its_tier(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Plan::where('code', 'basic')->update(['monthly_price' => 555]);   // differs from config on purpose

        $this->assertEquals(555.0, app(PriceResolver::class)->effectivePrice($this->unboundSubscription('basic')));
    }

    public function test_outside_the_test_runner_a_tier_with_no_plan_row_is_refused_not_priced_at_zero(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $sub = $this->unboundSubscription('enterprise');   // Sahaj's legacy tier: no plan row, no config entry
        $this->app['env'] = 'production';

        try {
            app(PriceResolver::class)->effectivePrice($sub);
            $this->fail('A price with no plan row behind it must be refused.');
        } catch (PlanMissing $e) {
            $this->assertStringContainsString('enterprise', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    // ------------------------------------------------------------------
    // E1.11 — once per submitted form
    // ------------------------------------------------------------------

    private function payingAccount(): Account
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $tenant = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Counter']);
        $tenant->account->subscription->forceFill([
            'status' => 'active', 'interval' => 'monthly',
            'current_period_end' => Carbon::today(config('billing.timezone'))->addDays(10),
        ])->save();

        return $tenant->account->fresh();
    }

    private function asOperator(): static
    {
        return $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]))
            ->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function ledger(Account $account): int
    {
        return SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $account->id)->count();
    }

    public function test_a_double_submitted_renewal_is_done_once(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $account = $this->payingAccount();
        $form = ['action' => 'renew', 'amount' => 499, 'method' => 'cash', '_once' => (string) Str::uuid()];
        $this->asOperator();

        $this->post(route('superadmin.accounts.action', $account), $form)->assertSessionHas('status');
        $endAfterFirst = $account->subscription()->first()->current_period_end->toDateString();

        $this->post(route('superadmin.accounts.action', $account), $form)
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'already submitted'));

        $this->assertSame(1, $this->ledger($account), 'one payment on the ledger, not two');
        $this->assertSame($endAfterFirst, $account->subscription()->first()->current_period_end->toDateString(), 'renewed once');
    }

    public function test_a_double_submitted_payment_is_recorded_once(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $account = $this->payingAccount();
        $form = ['for' => 'renewal', 'amount' => 499, 'method' => 'cash', 'paid_at' => now()->toDateString(), '_once' => (string) Str::uuid()];
        $this->asOperator();

        $this->post(route('superadmin.accounts.payment', $account), $form);
        $this->post(route('superadmin.accounts.payment', $account), $form);

        $this->assertSame(1, $this->ledger($account));
    }

    public function test_a_failed_attempt_does_not_spend_its_token(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $account = $this->payingAccount();
        $token = (string) Str::uuid();
        $this->asOperator();

        // Refused by the engine (a zero-rupee renewal is not a payment)…
        $this->post(route('superadmin.accounts.action', $account), ['action' => 'renew', 'amount' => 0, 'method' => 'cash', '_once' => $token])
            ->assertSessionHas('error');

        // …so the corrected form, same token, still goes through.
        $this->post(route('superadmin.accounts.action', $account), ['action' => 'renew', 'amount' => 499, 'method' => 'cash', '_once' => $token])
            ->assertSessionHas('status');

        $this->assertSame(1, $this->ledger($account));
    }

    public function test_every_operator_dialog_carries_a_fresh_one_time_token(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $account = $this->payingAccount();

        $html = $this->asOperator()->get(route('superadmin.accounts.show', $account))->assertOk()->getContent();
        preg_match_all('/name="_once" value="([0-9a-f-]{36})"/', $html, $m);

        $this->assertNotEmpty($m[1]);
        $this->assertSame(count($m[1]), count(array_unique($m[1])), 'each dialog has its own token');
    }
}
