<?php

namespace Tests\Feature;

use App\Mail\RenewalReminderMail;
use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\UnappliedPayment;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\Fakes\FakeRazorpay;
use Tests\TestCase;

/**
 * The E1–E4 audit (18_E1_E4_AUDIT.md): one test per finding, F1–F9, and the
 * owner's decision A-22 (a free period's end is announced the day before and on the day).
 */
class Phase89AuditTest extends TestCase
{
    use RefreshDatabase;

    private FakeRazorpay $rzp;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Plan::where('code', 'basic')->update(['monthly_price' => 499, 'yearly_price' => 4999, 'price_tiers' => null]);
        $this->rzp = FakeRazorpay::install($this->app);
        $this->on('2026-11-01 10:00');
        $this->operator = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
        $this->actingAs($this->operator);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function on(string $at): void
    {
        Carbon::setTestNow(Carbon::parse($at, config('billing.timezone')));
    }

    /** @return array{0: Tenant, 1: User} */
    private function customer(string $endsOn, string $status = 'active'): array
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $hq = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Audit Optical']);
        $this->sub($hq)->forceFill(['status' => $status, 'interval' => 'monthly', 'current_period_end' => $endsOn])->save();

        return [$hq, $owner->fresh()];
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    /** The owner pays a bill online, end to end through the engine; returns [bill, payment id]. */
    private function paidOnline(Tenant $hq): array
    {
        $bill = app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'owner']);
        $bill = app(BillingEngine::class)->openCheckout($bill, fn ($b) => $this->rzp->createOrder($b));
        $paymentId = $this->rzp->pay($bill->gateway_order_id, (float) $bill->amount);
        $this->assertSame('settled', app(Settlement::class)->settleGateway($this->rzp->fetchPayment($paymentId), 'callback')['outcome']);

        return [$bill->fresh(), $paymentId];
    }

    public function test_f1_a_reversed_online_payment_can_be_paid_online_again_with_a_new_order(): void
    {
        [$hq] = $this->customer('2026-11-05');
        [$bill] = $this->paidOnline($hq);
        $oldOrder = $bill->gateway_order_id;

        app(Settlement::class)->reverse(SubscriptionInvoice::withoutGlobalScopes()->where('charge_id', $bill->id)->sole(), 'refunded in Razorpay');

        $reopened = $bill->fresh();
        $this->assertSame('pending', $reopened->status);
        $this->assertNull($reopened->gateway_order_id, 'the old order is paid at Razorpay — never reused');

        $again = app(BillingEngine::class)->openCheckout($reopened, fn ($b) => $this->rzp->createOrder($b));
        $this->assertNotSame($oldOrder, $again->gateway_order_id);
    }

    public function test_f2_a_late_confirmation_of_money_already_on_the_ledger_is_never_recorded_again(): void
    {
        [$hq] = $this->customer('2026-11-05');
        [$bill, $paymentId] = $this->paidOnline($hq);
        app(Settlement::class)->reverse(SubscriptionInvoice::withoutGlobalScopes()->where('charge_id', $bill->id)->sole(), 'refunded');

        // The webhook for the ORIGINAL payment arrives late; its bill no longer carries the order.
        $result = app(Settlement::class)->settleGateway($this->rzp->fetchPayment($paymentId), 'webhook', 'order.paid');

        $this->assertSame('already', $result['outcome']);
        $this->assertSame(0, UnappliedPayment::count(), 'not flagged as money nobody accounted for');

        // And through the webhook door: a payment.captured with no OSMS notes is still ours.
        auth()->logout();
        [$body, $headers] = FakeRazorpay::webhook('payment.captured', ['payment' => ['entity' => $this->rzp->fetchPayment($paymentId)]]);
        $this->call('POST', route('webhooks.razorpay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Razorpay-Signature' => $headers['X-Razorpay-Signature'],
            'HTTP_X-Razorpay-Event-Id' => $headers['X-Razorpay-Event-Id'],
        ], $body)->assertOk();

        $this->assertSame(0, AdminAuditLog::where('action', 'payment.not_osms')->count(), 'money we hold is never "not OSMS"');
        $this->assertSame(0, UnappliedPayment::count());
    }

    public function test_f3_reactivating_reads_the_billing_clock_not_utc(): void
    {
        [$hq] = $this->customer('2026-11-01');
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'suspend', ['reason' => 'dispute']);

        $this->on('2026-11-02 01:00');   // 1 Nov in UTC, 2 Nov in India: their period has ended
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'reactivate', ['reason' => 'sorted']);

        $this->assertSame('past_due', $this->sub($hq)->status);
    }

    public function test_f4_undoing_a_cancellation_lets_the_clock_bill_the_renewal_again(): void
    {
        [$hq] = $this->customer('2026-11-06');
        PlatformSetting::set(PlatformSetting::RENEWAL_REMINDERS, true);
        Mail::fake();
        app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'system']);

        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'cancel', ['at_period_end' => true, 'reason' => 'thinking of leaving']);
        $this->assertSame('ending', BillingCharge::where('account_id', $hq->account_id)->where('status', 'void')->sole()->voided_via);
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'reactivate', ['reason' => 'they stayed']);

        auth()->logout();
        Artisan::call('billing:daily');

        $this->assertSame(1, BillingCharge::where('account_id', $hq->account_id)->pending()->count(), 'billed again — a cancellation\'s void is not "you voided this"');
    }

    public function test_f4_a_persons_own_void_is_still_respected(): void
    {
        [$hq] = $this->customer('2026-11-06');
        $bill = app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'system']);
        app(BillingEngine::class)->void($bill, 'paying by cheque');
        $this->assertSame('operator', $bill->fresh()->voided_via);

        auth()->logout();
        Artisan::call('billing:daily');

        $this->assertSame(0, BillingCharge::where('account_id', $hq->account_id)->pending()->count());
    }

    public function test_f5_extending_a_former_payer_is_paid_time_not_a_trial(): void
    {
        [$paid] = $this->customer('2026-11-05');
        app(Settlement::class)->payTerm($this->sub($paid), 'renew', ['method' => 'cash']);
        app(SubscriptionLifecycle::class)->commit($this->sub($paid), 'force_expire', ['reason' => 'test']);

        app(SubscriptionLifecycle::class)->commit($this->sub($paid), 'extend', ['days' => 7, 'reason' => 'goodwill']);
        $this->assertSame('active', $this->sub($paid)->status);

        [$never] = $this->customer('2026-10-20', 'canceled');
        app(SubscriptionLifecycle::class)->commit($this->sub($never), 'extend', ['days' => 7, 'reason' => 'second look']);
        $this->assertSame('trialing', $this->sub($never)->status, 'never paid: a trial is still the honest word');
    }

    public function test_f7_money_never_lifts_a_suspension(): void
    {
        [$hq, $owner] = $this->customer('2026-11-05');
        $bill = app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'owner']);
        $bill = app(BillingEngine::class)->openCheckout($bill, fn ($b) => $this->rzp->createOrder($b));   // checkout opened…
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'suspend', ['reason' => 'dispute']); // …then suspended
        $paymentId = $this->rzp->pay($bill->gateway_order_id, 499);

        app(Settlement::class)->settleGateway($this->rzp->fetchPayment($paymentId), 'webhook', 'order.paid');

        $sub = $this->sub($hq);
        $this->assertTrue($sub->isSuspended(), 'still suspended until a person reactivates');
        $this->assertSame('locked', $sub->accessState());
        $this->assertSame('2026-12-05', $sub->current_period_end->toDateString(), 'the paid month is kept for them');
        $this->assertTrue(AdminAuditLog::where('action', 'subscription.renew')->sole()->meta['still_suspended']);

        // Reactivating gives back exactly what they paid for.
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'reactivate', ['reason' => 'dispute settled']);
        $this->assertSame('active', $this->sub($hq)->accessState());
    }

    public function test_f7_a_suspended_owner_is_not_offered_a_payment(): void
    {
        [$hq, $owner] = $this->customer('2026-11-05');
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'suspend', ['reason' => 'dispute']);

        // The lock screen already keeps a suspended store away from the pay page; the pay
        // door refuses on its own too, in case a route ever slips past the lock.
        $this->actingAs($owner)->post(route('tenant.billing.subscribe'), ['interval' => 'monthly'])->assertRedirect();
        $this->withoutMiddleware(\App\Http\Middleware\EnsureSubscriptionActive::class)
            ->actingAs($owner)->post(route('tenant.billing.subscribe'), ['interval' => 'monthly'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'paused by our team'));

        $this->assertSame(0, BillingCharge::where('account_id', $hq->account_id)->count(), 'no bill, no order');
    }

    public function test_f8_a_cancellation_they_scheduled_ends_with_the_period_not_as_an_overdue_lapse(): void
    {
        [$hq] = $this->customer('2026-11-05');
        app(SubscriptionLifecycle::class)->ownerCancelAtPeriodEnd($this->sub($hq));
        auth()->logout();

        $this->on('2026-11-05 10:00');
        Artisan::call('subscriptions:reconcile');
        $this->assertSame('active', $this->sub($hq)->status, 'their last day is still theirs');

        $this->on('2026-11-06 10:00');
        Artisan::call('subscriptions:reconcile');

        $sub = $this->sub($hq);
        $this->assertSame(['canceled', 'locked', false], [$sub->status, $sub->accessState(), (bool) $sub->cancel_at_period_end]);
        $this->assertNotNull($sub->churned_at);
        $log = AdminAuditLog::where('action', 'subscription.cancelled_at_period_end')->sole();
        $this->assertSame('System', $log->actorLabel());
        $this->assertSame(0, Subscription::withoutGlobalScopes()->overdue()->count(), 'never counted as overdue');
    }

    public function test_f9_a_payment_is_reversed_once_even_from_two_stale_pages(): void
    {
        [$hq] = $this->customer('2026-11-05');
        $row = app(Settlement::class)->payTerm($this->sub($hq), 'renew', ['method' => 'cash']);
        $pageA = SubscriptionInvoice::withoutGlobalScopes()->find($row->id);
        $pageB = SubscriptionInvoice::withoutGlobalScopes()->find($row->id);

        app(Settlement::class)->reverse($pageA, 'mistyped');

        try {
            app(Settlement::class)->reverse($pageB, 'a second operator');
            $this->fail('the second reversal must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already been reversed', $e->getMessage());
        }

        $this->assertSame('mistyped', $row->fresh()->reversal_reason);
        $this->assertSame(1, AdminAuditLog::where('action', 'payment.reversed')->count());
    }

    public function test_a22_a_free_periods_end_is_announced_the_day_before_and_on_the_day_only(): void
    {
        PlatformSetting::set(PlatformSetting::RENEWAL_REMINDERS, true);
        Mail::fake();
        [$hq, $owner] = $this->customer('2026-10-08');
        $this->on('2026-10-01 10:00');
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'comp', ['months' => 1, 'reason' => 'launch offer']);   // free until 8 Nov
        auth()->logout();

        foreach (['2026-11-01', '2026-11-07', '2026-11-08', '2026-11-10'] as $day) {
            $this->on($day . ' 08:00');
            Artisan::call('billing:daily');
        }

        $sent = Mail::queued(RenewalReminderMail::class);
        $this->assertSame(['due_1', 'due_0'], $sent->map->stage->values()->all(), 'not 7 days ahead, not in grace');
        $this->assertTrue($sent->every(fn ($m) => $m->freePeriod && abs($m->amount - 499) < 0.01 && $m->billNumber === null));
        $this->assertSame('Your free OSMS period ends tomorrow — ₹499.00 to carry on', $sent->first()->envelope()->subject);
        $this->assertStringContainsString('free period on your OSMS plan ends', strip_tags($sent->first()->render()));
        $this->assertSame(0, BillingCharge::where('account_id', $hq->account_id)->count(), 'still never billed ahead');

        // The banner says it too, on those days.
        $this->on('2026-11-07 10:00');
        $this->actingAs($owner)->get(route('tenant.dashboard'))->assertSee('Your renewal of', false)->assertSee('₹ 499.00');
    }
}
