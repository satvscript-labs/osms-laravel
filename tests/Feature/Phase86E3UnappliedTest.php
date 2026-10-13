<?php

namespace Tests\Feature;

use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\UnappliedPayment;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E3.6 — the operator resolves money received online that is on no bill
 * (FB-04, rule 4; 14_E3_PLAN.md edges 22–24).
 */
class Phase86E3UnappliedTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->operator = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
        $this->actingAs($this->operator);
    }

    private function customer(string $name): Tenant
    {
        $hq = app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']), ['store_name' => $name]);
        Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->firstOrFail()
            ->forceFill(['status' => 'active', 'interval' => 'monthly', 'current_period_end' => Carbon::today(config('billing.timezone'))->addDays(4)])->save();

        return $hq;
    }

    private function money(?Tenant $of, float $rupees, string $id = 'pay_U1'): UnappliedPayment
    {
        return app(Settlement::class)->recordUnapplied(
            ['id' => $id, 'order_id' => 'order_X', 'amount' => (int) round($rupees * 100), 'currency' => 'INR', 'status' => 'captured', 'created_at' => now()->timestamp],
            null, $of?->account_id, 'test: matched no bill', 'order.paid');
    }

    private function bill(Tenant $hq): BillingCharge
    {
        return app(BillingEngine::class)->raiseTerm(Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->firstOrFail());
    }

    private function op(): self
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_today_raises_it_and_the_billing_page_lists_it(): void
    {
        $hq = $this->customer('Known');
        $this->money($hq, 499, 'pay_KNOWN');
        $this->money(null, 750, 'pay_STRAY');

        $this->op()->get(route('superadmin.dashboard'))->assertOk()
            ->assertSee('₹ 1,249.00 received online is not on any bill')
            ->assertSee('2 payments to resolve');

        $this->op()->get(route('superadmin.billing.index'))->assertOk()
            ->assertSee('Received online · not on any bill')
            ->assertSee('pay_KNOWN')->assertSee('Apply…')
            ->assertSee('pay_STRAY')->assertSee('Customer unknown')->assertSee('Whose is it?');
    }

    public function test_an_unknown_payment_is_matched_to_a_customer_with_a_reason_then_applied_on_their_page(): void
    {
        $hq = $this->customer('Claimed');
        $stray = $this->money(null, 499, 'pay_STRAY');
        $bill = $this->bill($hq);

        $this->op()->post(route('superadmin.unapplied.assign', $stray->id), ['account' => $hq->account_id, '_once' => (string) Str::uuid()])
            ->assertSessionHasErrors('note');

        $this->op()->post(route('superadmin.unapplied.assign', $stray->id), [
            'account' => $hq->account_id, 'note' => 'they emailed the Razorpay receipt', '_once' => (string) Str::uuid(),
        ])->assertRedirect(route('superadmin.accounts.show', $hq->account_id) . '#tab-owed');

        $this->assertSame($hq->account_id, $stray->fresh()->account_id);

        $this->op()->get(route('superadmin.accounts.show', $hq->account_id))->assertOk()
            ->assertSee('Received online · not on a bill')
            ->assertSee('id="m-apply-' . $stray->id . '"', false);

        $this->op()->post(route('superadmin.accounts.unapplied.apply', [$hq->account_id, $stray->id]), [
            'charge' => $bill->id, 'note' => 'their renewal', '_once' => (string) Str::uuid(),
        ])->assertSessionHas('status', fn ($m) => str_contains($m, 'Applied'));

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame('applied', $stray->fresh()->status);
        $this->assertSame('pay_STRAY', SubscriptionInvoice::withoutGlobalScopes()->sole()->razorpay_payment_id);
        $this->assertStringEndsWith('from money received online', \App\Models\AdminAuditLog::where('action', 'subscription.renew')->sole()->description);
    }

    public function test_applying_is_previewed_and_the_preview_writes_nothing(): void
    {
        $hq = $this->customer('Preview');
        $u = $this->money($hq, 499);
        $bill = $this->bill($hq);

        $preview = $this->op()->postJson(route('superadmin.accounts.preview', $hq->account_id), [
            'action' => 'apply_unapplied', 'unapplied' => $u->id, 'charge' => $bill->id, 'note' => 'x',
        ])->json();

        $this->assertNull($preview['error']);
        $this->assertSame(1, $preview['ledger_rows_added']);
        $this->assertEquals(0, $preview['changes']['owed']['to']);
        $this->assertSame('open', $u->fresh()->status);
        $this->assertSame('pending', $bill->fresh()->status);
    }

    public function test_one_customers_money_never_lands_on_another_and_amounts_must_match(): void
    {
        $mine = $this->customer('Mine');
        $theirs = $this->customer('Theirs');
        $u = $this->money($theirs, 499);
        $myBill = $this->bill($mine);

        $this->op()->post(route('superadmin.accounts.unapplied.apply', [$mine->account_id, $u->id]), [
            'charge' => $myBill->id, 'note' => 'x', '_once' => (string) Str::uuid(),
        ])->assertNotFound();

        $yearly = app(BillingEngine::class)->raiseTerm(Subscription::withoutGlobalScopes()->where('account_id', $theirs->account_id)->firstOrFail(), ['interval' => 'yearly']);
        $this->op()->post(route('superadmin.accounts.unapplied.apply', [$theirs->account_id, $u->id]), [
            'charge' => $yearly->id, 'note' => 'x', '_once' => (string) Str::uuid(),
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'does not match'));

        $this->assertSame('open', $u->fresh()->status);
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    public function test_with_no_open_bill_the_money_pays_their_next_renewal(): void
    {
        $hq = $this->customer('Renewal');
        $u = $this->money($hq, 499, 'pay_AUTOPAY');
        $before = Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->firstOrFail()->current_period_end->copy();

        $this->op()->get(route('superadmin.accounts.show', $hq->account_id))->assertOk()
            ->assertSee('Their next renewal (monthly) — ₹ 499.00');

        // The preview raises nothing for real.
        $preview = $this->op()->postJson(route('superadmin.accounts.preview', $hq->account_id), [
            'action' => 'apply_unapplied', 'unapplied' => $u->id, 'charge' => 'renewal', 'note' => 'x',
        ])->json();
        $this->assertNull($preview['error']);
        $this->assertSame(1, $preview['ledger_rows_added']);
        $this->assertSame(0, BillingCharge::count());

        $this->op()->post(route('superadmin.accounts.unapplied.apply', [$hq->account_id, $u->id]), [
            'charge' => 'renewal', 'note' => 'old autopay charge for this month', '_once' => (string) Str::uuid(),
        ])->assertSessionHas('status', fn ($m) => str_contains($m, 'Applied'));

        $bill = BillingCharge::sole();
        $this->assertSame(['renewal', 'paid'], [$bill->kind, $bill->status]);
        $this->assertSame('applied', $u->fresh()->status);
        $this->assertSame($bill->id, $u->fresh()->charge_id);
        $sub = Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->firstOrFail();
        $this->assertSame('active', $sub->accessState());
        $this->assertTrue($sub->current_period_end->gt($before), 'a month bought');
        $this->assertEquals(499.0, (float) SubscriptionInvoice::withoutGlobalScopes()->collected()->sum('amount'), 'collected once applied');

        $this->op()->get(route('superadmin.accounts.show', $hq->account_id))->assertOk()
            ->assertSee('<i class="bi bi-hand-index-thumb"></i> Paid renewal', false)
            ->assertDontSee('<i class="bi bi-hand-index-thumb"></i> manual_renewal', false);
    }

    public function test_a_renewal_of_another_amount_raises_nothing_and_keeps_the_money(): void
    {
        $hq = $this->customer('Mismatch');
        $u = $this->money($hq, 300, 'pay_ODD');

        $this->op()->get(route('superadmin.accounts.show', $hq->account_id))->assertOk()
            ->assertSee('— ₹ 499.00 (different amount)');

        $this->op()->post(route('superadmin.accounts.unapplied.apply', [$hq->account_id, $u->id]), [
            'charge' => 'renewal', 'note' => 'x', '_once' => (string) Str::uuid(),
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'does not match'));

        $this->assertSame(0, BillingCharge::count(), 'the raised renewal rolled back with the refusal');
        $this->assertSame('open', $u->fresh()->status);
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    public function test_the_preview_asks_for_a_bill_before_one_is_chosen(): void
    {
        $hq = $this->customer('Empty');
        $u = $this->money($hq, 499);

        $this->assertSame('Choose the bill this is for.', $this->op()->postJson(route('superadmin.accounts.preview', $hq->account_id), [
            'action' => 'apply_unapplied', 'unapplied' => $u->id, 'charge' => '', 'note' => '',
        ])->json('error'));
    }

    public function test_recording_a_refund_needs_a_reference_and_clears_the_alert(): void
    {
        $u = $this->money(null, 300, 'pay_REFUND');

        $this->op()->post(route('superadmin.unapplied.refunded', $u->id), ['_once' => (string) Str::uuid()])->assertSessionHasErrors('note');

        $this->op()->post(route('superadmin.unapplied.refunded', $u->id), ['note' => 'rfnd_123', '_once' => (string) Str::uuid()])
            ->assertSessionHas('status');

        $this->assertSame('refunded', $u->fresh()->status);
        $this->op()->get(route('superadmin.dashboard'))->assertDontSee('received online is not on any bill');
    }
}
