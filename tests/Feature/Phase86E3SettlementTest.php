<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\UnappliedPayment;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\MoneyWrite;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Fakes\FakeRazorpay;
use Tests\TestCase;

/**
 * E3.2 — Razorpay's money through the same Settlement as cash (14_E3_PLAN.md §4–5).
 *
 * Settled once whoever reports it first; never dropped (unapplied + alert); never
 * paid twice while the owner is mid-checkout (E3-B).
 */
class Phase86E3SettlementTest extends TestCase
{
    use RefreshDatabase;

    private FakeRazorpay $rzp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->rzp = FakeRazorpay::install($this->app);
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
    }

    private function settlement(): Settlement
    {
        return app(Settlement::class);
    }

    private function paying(string $name = 'Online'): Tenant
    {
        $hq = app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']), ['store_name' => $name]);
        $this->sub($hq)->forceFill(['status' => 'active', 'interval' => 'monthly', 'current_period_end' => Carbon::today(config('billing.timezone'))->addDays(5)])->save();

        return $hq;
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    /** A term bill with a Razorpay order, as the owner's Pay button leaves it. */
    private function billWithOrder(Tenant $hq, bool $checkoutJustOpened = false): BillingCharge
    {
        $bill = app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'owner']);
        $order = $this->rzp->createOrder($bill);
        MoneyWrite::during(fn () => $bill->forceFill([
            'gateway_order_id' => $order,
            'gateway_checkout_at' => $checkoutJustOpened ? now() : now()->subHour(),
        ])->save());

        return $bill->fresh();
    }

    private function collected(): float
    {
        return (float) SubscriptionInvoice::withoutGlobalScopes()->collected()->sum('amount');
    }

    // ------------------------------------------------------------------
    // Settled once
    // ------------------------------------------------------------------

    public function test_a_captured_payment_for_the_bill_settles_it_like_cash_and_names_the_gateway(): void
    {
        $hq = $this->paying();
        $bill = $this->billWithOrder($hq);
        $end = $this->sub($hq)->current_period_end->copy();
        $pay = $this->rzp->pay($bill->gateway_order_id, 499);

        $result = $this->settlement()->settleGateway($this->rzp->payments[$pay], 'callback');

        $this->assertSame('settled', $result['outcome']);
        $row = $result['row'];
        $this->assertSame('razorpay', $row->method);
        $this->assertSame('gateway', $row->source);
        $this->assertSame($pay, $row->razorpay_payment_id);
        $this->assertSame($bill->id, $row->charge_id);
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame($end->copy()->addMonth()->toDateString(), $this->sub($hq)->current_period_end->toDateString());
        $this->assertSame('Gateway (Razorpay)', AdminAuditLog::where('action', 'payment.recorded')->sole()->actorLabel());
        $this->assertSame('Gateway (Razorpay)', AdminAuditLog::where('action', 'subscription.renew')->sole()->actorLabel());
        $this->assertEquals(499.0, $this->collected());
    }

    public function test_a_payment_made_just_now_is_never_dated_in_the_future_whatever_the_hour(): void
    {
        // 01:00 UTC = 06:30 IST, same date. Taking the payment's day in UTC and
        // comparing it with today in IST refused real payments in this window.
        Carbon::setTestNow(Carbon::parse('2026-10-14 01:00:00', 'UTC'));

        try {
            $hq = $this->paying();
            $bill = $this->billWithOrder($hq);
            $result = $this->settlement()->settleGateway($this->rzp->payments[$this->rzp->pay($bill->gateway_order_id, 499)], 'callback');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('settled', $result['outcome']);
    }

    public function test_the_callback_and_the_webhook_for_one_payment_record_it_once(): void
    {
        $hq = $this->paying();
        $bill = $this->billWithOrder($hq);
        $pay = $this->rzp->pay($bill->gateway_order_id, 499);

        $first = $this->settlement()->settleGateway($this->rzp->payments[$pay], 'callback');
        $end = $this->sub($hq)->current_period_end->toDateString();
        $second = $this->settlement()->settleGateway($this->rzp->payments[$pay], 'webhook', 'order.paid');

        $this->assertSame('settled', $first['outcome']);
        $this->assertSame('already', $second['outcome']);
        $this->assertSame($first['row']->id, $second['row']->id);
        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()->count());
        $this->assertSame($end, $this->sub($hq)->current_period_end->toDateString(), 'one month, not two');
    }

    public function test_an_authorized_but_uncaptured_payment_changes_nothing_yet(): void
    {
        $hq = $this->paying();
        $bill = $this->billWithOrder($hq);
        $pay = $this->rzp->pay($bill->gateway_order_id, 499, 'authorized');

        $this->assertSame('not_captured', $this->settlement()->settleGateway($this->rzp->payments[$pay], 'callback')['outcome']);
        $this->assertSame('pending', $bill->fresh()->status);
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count() + UnappliedPayment::count());
    }

    // ------------------------------------------------------------------
    // Never dropped: unapplied
    // ------------------------------------------------------------------

    public function test_money_for_an_order_we_do_not_know_is_kept_and_flagged(): void
    {
        $pay = $this->rzp->pay('order_NOBODY', 750);

        $result = $this->settlement()->settleGateway($this->rzp->payments[$pay], 'webhook', 'order.paid');

        $this->assertSame('unapplied', $result['outcome']);
        $u = $result['unapplied'];
        $this->assertNull($u->account_id);
        $this->assertEquals(750.0, (float) $u->amount);
        $this->assertStringContainsString('no bill for', $u->reason);
        $this->assertSame(1, AdminAuditLog::where('action', 'payment.unapplied')->count());
        $this->assertEquals(0.0, $this->collected(), 'not revenue until a person applies it');

        $again = $this->settlement()->settleGateway($this->rzp->payments[$pay], 'webhook', 'payment.captured');
        $this->assertSame($u->id, $again['unapplied']->id, 'kept once');
        $this->assertSame(1, UnappliedPayment::count());
    }

    public function test_money_for_a_bill_already_paid_by_cash_is_kept_for_a_refund(): void
    {
        $hq = $this->paying();
        $bill = $this->billWithOrder($hq);
        $this->settlement()->settle($bill, ['method' => 'cash']);   // paid at the counter meanwhile
        $pay = $this->rzp->pay($bill->gateway_order_id, 499);

        $result = $this->settlement()->settleGateway($this->rzp->payments[$pay], 'webhook', 'order.paid');

        $this->assertSame('unapplied', $result['outcome']);
        $this->assertStringContainsString('refund it', $result['unapplied']->reason);
        $this->assertSame($hq->account_id, $result['unapplied']->account_id);
        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()->count(), 'the bill is not paid twice');
    }

    public function test_a_different_amount_never_settles_the_bill(): void
    {
        $hq = $this->paying();
        $bill = $this->billWithOrder($hq);
        $pay = $this->rzp->pay($bill->gateway_order_id, 400);

        $result = $this->settlement()->settleGateway($this->rzp->payments[$pay], 'callback');

        $this->assertSame('unapplied', $result['outcome']);
        $this->assertStringContainsString('amounts differ', $result['unapplied']->reason);
        $this->assertSame('pending', $bill->fresh()->status, 'still owed');
    }

    // ------------------------------------------------------------------
    // The operator resolves unapplied money
    // ------------------------------------------------------------------

    public function test_unapplied_money_can_be_applied_once_to_a_bill_of_the_same_amount(): void
    {
        $hq = $this->paying();
        $u = $this->settlement()->recordUnapplied($this->rzp->payments[$this->rzp->pay('order_LOST', 499)] , null, null, 'lost order');
        $bill = app(BillingEngine::class)->raiseTerm($this->sub($hq));
        $other = $this->paying('Other');
        $otherBill = app(BillingEngine::class)->raiseTerm($this->sub($other), ['interval' => 'yearly']);

        try {
            $this->settlement()->applyUnapplied($u, $otherBill, 'theirs');
            $this->fail('₹499 must not settle a ₹4,999 bill');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not match', $e->getMessage());
        }

        $row = $this->settlement()->applyUnapplied($u, $bill, 'customer phoned — paid for this renewal');

        $this->assertSame('applied', $u->fresh()->status);
        $this->assertSame($row->id, $u->fresh()->ledger_row_id);
        $this->assertSame($hq->account_id, $u->fresh()->account_id);
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame('razorpay', $row->method);
        $this->assertEquals(499.0, $this->collected(), 'counted once applied');

        $this->expectException(InvalidArgumentException::class);
        $this->settlement()->applyUnapplied($u->fresh(), $bill, 'again');
    }

    public function test_money_from_one_customer_cannot_be_applied_to_another(): void
    {
        $hq = $this->paying();
        $bill = $this->billWithOrder($hq);
        $this->settlement()->settle($bill, ['method' => 'cash']);
        $u = $this->settlement()->settleGateway($this->rzp->payments[$this->rzp->pay($bill->gateway_order_id, 499)], 'webhook')['unapplied'];
        $other = $this->paying('Other');
        $theirs = app(BillingEngine::class)->raiseTerm($this->sub($other));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('different customer');
        $this->settlement()->applyUnapplied($u, $theirs, 'wrong account');
    }

    public function test_marking_unapplied_money_refunded_needs_a_note_and_closes_it(): void
    {
        $u = $this->settlement()->recordUnapplied($this->rzp->payments[$this->rzp->pay('order_X', 300)], null, null, 'stray');

        try {
            $this->settlement()->markRefunded($u, ' ');
            $this->fail('a refund must be explained');
        } catch (InvalidArgumentException) {
        }

        $this->settlement()->markRefunded($u, 'rfnd_123 in the Razorpay dashboard');

        $this->assertSame('refunded', $u->fresh()->status);
        $this->assertEquals(0.0, $this->collected());
        $this->assertSame(1, AdminAuditLog::where('action', 'payment.unapplied_refunded')->count());
    }

    // ------------------------------------------------------------------
    // E3-B — never paid twice while the owner is mid-checkout
    // ------------------------------------------------------------------

    public function test_while_the_owner_is_paying_online_the_operator_cannot_take_cash_waive_or_void(): void
    {
        $hq = $this->paying();
        $bill = $this->billWithOrder($hq, checkoutJustOpened: true);

        foreach ([
            fn () => $this->settlement()->settle($bill->fresh(), ['method' => 'cash']),
            fn () => $this->settlement()->waive($bill->fresh(), 'goodwill'),
            fn () => app(BillingEngine::class)->void($bill->fresh(), 'error'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('refused while the owner may be paying');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('being paid online right now', $e->getMessage());
            }
        }

        $this->assertSame('pending', $bill->fresh()->status);
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());

        // …but the payment itself goes through: it IS the online payment.
        $result = $this->settlement()->settleGateway($this->rzp->payments[$this->rzp->pay($bill->gateway_order_id, 499)], 'callback');
        $this->assertSame('settled', $result['outcome']);
    }

    public function test_an_ending_still_voids_a_bill_mid_checkout_and_late_money_is_kept(): void
    {
        $hq = $this->paying();
        $bill = $this->billWithOrder($hq, checkoutJustOpened: true);

        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'force_expire', ['reason' => 'fraud']);
        $this->assertSame('void', $bill->fresh()->status, 'the operator\'s ending wins');

        $late = $this->settlement()->settleGateway($this->rzp->payments[$this->rzp->pay($bill->gateway_order_id, 499)], 'webhook', 'order.paid');
        $this->assertSame('unapplied', $late['outcome'], 'the money arrived anyway: kept for a refund');
        $this->assertSame('canceled', $this->sub($hq)->status, 'and access stays ended');
    }

    public function test_after_the_window_the_operator_can_act_again(): void
    {
        $hq = $this->paying();
        $bill = $this->billWithOrder($hq, checkoutJustOpened: true);

        Carbon::setTestNow(now()->addMinutes(BillingCharge::CHECKOUT_WINDOW_MINUTES + 1));

        try {
            $row = $this->settlement()->settle($bill->fresh(), ['method' => 'cash']);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame('cash', $row->method);
    }
}
