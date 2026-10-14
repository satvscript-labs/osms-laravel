<?php

namespace Tests\Feature;

use App\Models\BillingCharge;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeRazorpay;
use Tests\TestCase;

/**
 * E3.5 — the owner's Subscription page at parity (3.4; FB-07, FB-25, edges 26–27).
 */
class Phase86E3OwnerPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Plan::where('code', 'basic')->update(['monthly_price' => 499, 'yearly_price' => 4999, 'price_tiers' => null]);
        FakeRazorpay::install($this->app);
    }

    /** @return array{0: Tenant, 1: User} */
    private function customer(): array
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $hq = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Page Optical']);
        $this->sub($hq)->forceFill(['status' => 'active', 'interval' => 'monthly', 'current_period_end' => Carbon::today(config('billing.timezone'))->addDays(9)])->save();

        return [$hq, $owner->fresh()];
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    private function addBranch(Tenant $hq): Tenant
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        $b = app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']), ['store_name' => 'Page — B2'], $hq->account->fresh());
        auth()->logout();

        return $b;
    }

    public function test_the_owner_sees_the_price_for_their_branches_from_the_engine(): void
    {
        [$hq, $owner] = $this->customer();
        $this->addBranch($hq);

        $this->actingAs($owner)->get(route('tenant.billing.index'))->assertOk()
            ->assertSee('₹998.00')            // 2 branches × ₹499 — not the config ₹499
            ->assertSee('For 2 branches');

        // A price change on the Plans page reaches the owner without a deploy (REQ-4).
        Plan::where('code', 'basic')->update(['monthly_price' => 599]);
        $this->actingAs($owner->fresh())->get(route('tenant.billing.index'))->assertSee('₹1,198.00');
    }

    public function test_an_agreed_price_is_what_the_owner_sees_and_pays(): void
    {
        [$hq, $owner] = $this->customer();
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'set_price', ['price' => 399, 'interval' => 'monthly', 'reason' => 'founding customer']);
        auth()->logout();

        $this->actingAs($owner)->get(route('tenant.billing.index'))->assertOk()
            ->assertSee('₹399.00')
            ->assertSee('your agreed price');
    }

    public function test_the_owner_can_pay_each_open_bill_and_a_branch_admin_is_told_who_can(): void
    {
        [$hq, $owner] = $this->customer();
        $branch = $this->addBranch($hq);
        $bill = BillingCharge::where('account_id', $hq->account_id)->sole();

        $this->actingAs($owner)->get(route('tenant.billing.index'))->assertOk()
            ->assertSee('Pay ₹ ' . number_format((float) $bill->amount, 2) . ' now')
            ->assertSee('value="' . $bill->id . '"', false);

        $branchAdmin = User::where('tenant_id', $branch->id)->firstOrFail();
        $this->actingAs($branchAdmin)->get(route('tenant.billing.index'))->assertOk()
            ->assertSee($bill->number)
            ->assertDontSee('Pay ₹ ' . number_format((float) $bill->amount, 2) . ' now')
            ->assertSee('Only the account owner');
    }

    public function test_the_status_is_never_shown_raw(): void
    {
        [$hq, $owner] = $this->customer();
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        auth()->logout();
        // Overdue but inside grace: a person reads "In grace", never the column value.
        SubscriptionLifecycle::writing(fn () => $this->sub($hq)->forceFill(['status' => 'past_due', 'current_period_end' => now()->subDays(3)])->save());

        $this->actingAs($owner->fresh())->get(route('tenant.billing.index'))->assertOk()
            ->assertSee('In grace')
            ->assertDontSee('>past_due<', false);
    }

    public function test_the_pay_button_names_what_it_does(): void
    {
        [$hq, $owner] = $this->customer();

        $this->actingAs($owner)->get(route('tenant.billing.index'))->assertSee('Pay your next renewal early');

        SubscriptionLifecycle::writing(fn () => $this->sub($hq)->forceFill(['status' => 'trialing'])->save());
        $this->actingAs($owner->fresh())->get(route('tenant.billing.index'))->assertSee('Start your paid plan');

        SubscriptionLifecycle::writing(fn () => $this->sub($hq)->forceFill(['status' => 'past_due', 'current_period_end' => now()->subDays(20)])->save());
        $this->actingAs($owner->fresh())->get(route('tenant.billing.index'))->assertSee('Pay to restore access');
    }

    public function test_a_date_already_gone_is_never_called_the_next_billing(): void
    {
        [$hq, $owner] = $this->customer();
        $this->actingAs($owner)->get(route('tenant.billing.index'))->assertSee('Next billing');

        $set = fn (array $cols) => SubscriptionLifecycle::writing(fn () => $this->sub($hq)->forceFill($cols)->save());
        $page = fn () => $this->actingAs($owner->fresh())->get(route('tenant.billing.index'))->assertOk();

        $set(['status' => 'past_due', 'current_period_end' => now()->subDays(3)]);
        $page()->assertSee('Payment was due')->assertDontSee('Next billing');

        $set(['status' => 'canceled', 'current_period_end' => now()->subDays(40)]);
        $page()->assertSee('Access ended')->assertDontSee('Next billing');

        $set(['status' => 'trialing', 'current_period_end' => now()->subDays(2)]);
        $page()->assertSee('Trial ended')->assertDontSee('Trial ends');
    }
}
