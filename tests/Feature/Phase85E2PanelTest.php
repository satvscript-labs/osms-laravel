<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentRecorder;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * E2.6 — what the operator sees (11_E2_PLAN.md §5): one money lever that asks what
 * it pays for (D-5), the D-4 confirmation, the Owed tab, and figures from the
 * engine — Collected is money received, Owed is open bills, aged (06 §7).
 */
class Phase85E2PanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
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

    /** A paying account with an open branch charge. */
    private function owing(string $name = 'HQ'): array
    {
        $hq = $this->store($name);
        $this->sub($hq)->forceFill([
            'status' => 'active', 'interval' => 'monthly',
            'current_period_end' => Carbon::today(config('billing.timezone'))->addDays(20),
        ])->save();
        $this->store("{$name} — Branch 2", $hq->account->fresh());

        return [$hq, BillingCharge::where('account_id', $hq->account_id)->sole()];
    }

    private function page(Tenant $hq)
    {
        return $this->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('superadmin.accounts.show', $hq->account_id));
    }

    public function test_one_money_lever_that_asks_what_it_pays_for(): void
    {
        [$hq, $charge] = $this->owing();

        $this->page($hq)->assertOk()
            ->assertSee('What it pays for')
            ->assertSee('<option value="' . $charge->id . '">', false)
            ->assertSee('<option value="renewal">Their next renewal</option>', false)
            ->assertSee('name="cycles"', false)
            ->assertSee('name="confirm_over"', false)
            ->assertSee("previewAction: 'record_payment'", false)
            // D-5 — Renew now and Mark as paid are this lever now.
            ->assertDontSee('data-bs-target="#m-renew"', false)
            ->assertDontSee('data-bs-target="#m-markpaid"', false);
    }

    public function test_the_owed_tab_lists_the_bill_with_its_working_and_actions(): void
    {
        [$hq, $charge] = $this->owing();

        $this->page($hq)->assertOk()
            ->assertSee('Owed · ₹ ' . number_format((float) $charge->amount, 0))
            ->assertSee('₹ ' . number_format((float) $charge->amount, 2) . ' owed')
            ->assertSee($charge->number)
            ->assertSee('How this was worked out')
            ->assertSee('data-pay-for="' . $charge->id . '"', false)
            ->assertSee('id="m-waive-' . $charge->id . '"', false)
            ->assertSee('id="m-void-' . $charge->id . '"', false);
    }

    public function test_a_settled_bill_offers_no_actions(): void
    {
        [$hq, $charge] = $this->owing();
        app(Settlement::class)->settle($charge, ['method' => 'cash']);

        $this->page($hq)->assertOk()
            ->assertSee($charge->number)
            ->assertDontSee('data-pay-for="' . $charge->id . '"', false)
            ->assertDontSee('id="m-void-' . $charge->id . '"', false);
    }

    public function test_collected_is_money_received_only_everywhere(): void
    {
        [$hq] = $this->owing();
        $sub = $this->sub($hq);

        app(PaymentRecorder::class)->record($sub, 499, 'cash');                       // real money
        app(PaymentRecorder::class)->recordComp($sub, 'goodwill');                    // ₹0 grant
        $reversed = app(PaymentRecorder::class)->record($sub, 1000, 'upi');
        app(PaymentRecorder::class)->reverse($reversed, 'bounced');
        // A pre-engine pro-rata row (FB-18): "paid" money that was only ever owed.
        app(PaymentRecorder::class)->record($sub, 250, 'adjustment', ['reason' => 'Part period (legacy)']);

        $this->assertEquals(499.0, (float) SubscriptionInvoice::withoutGlobalScopes()->collected()->sum('amount'));

        $operator = $this->withSession(['auth.password_confirmed_at' => time()]);
        $operator->get(route('superadmin.dashboard'))->assertOk()
            ->assertViewHas('stats', fn ($s) => $s['collected_all'] === 499.0 && $s['collected_month'] === 499.0);
        $operator->get(route('superadmin.billing.index'))->assertOk()
            ->assertViewHas('totals', fn ($t) => $t['collected'] === 499.0);
        $operator->get(route('superadmin.accounts.show', $hq->account_id))->assertOk()
            ->assertViewHas('lifetime', 499.0);
    }

    public function test_today_shows_what_is_owed_aged(): void
    {
        [$a, $fresh] = $this->owing('A');
        [$b, $old] = $this->owing('B');
        \App\Services\MoneyWrite::during(fn () => $old->forceFill(['due_on' => now()->subDays(40)->toDateString()])->save());

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('superadmin.dashboard'))->assertOk()
            ->assertViewHas('owed', function ($o) use ($fresh, $old) {
                return $o['bills'] === 2 && $o['accounts'] === 2
                    && abs($o['total'] - ((float) $fresh->amount + (float) $old->amount)) < 0.01
                    && abs($o['aged']['fresh'] - (float) $fresh->amount) < 0.01
                    && abs($o['aged']['older'] - (float) $old->amount) < 0.01
                    && $o['aged']['month'] === 0.0;
            })
            ->assertSee('Owed');
    }
}
