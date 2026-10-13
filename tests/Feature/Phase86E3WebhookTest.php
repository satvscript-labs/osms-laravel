<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\UnappliedPayment;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\BillingEngine;
use App\Services\MoneyWrite;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeRazorpay;
use Tests\TestCase;

/**
 * E3.3 — the webhook (14_E3_PLAN.md §4–5, edges 13, 14, 16–20; E3-E, E3-G).
 */
class Phase86E3WebhookTest extends TestCase
{
    use RefreshDatabase;

    private FakeRazorpay $rzp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->rzp = FakeRazorpay::install($this->app);
    }

    private function paying(string $name = 'Hook'): Tenant
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        $hq = app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']), ['store_name' => $name]);
        Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->firstOrFail()
            ->forceFill(['status' => 'active', 'interval' => 'monthly', 'current_period_end' => Carbon::today(config('billing.timezone'))->addDays(5)])->save();
        auth()->logout();   // Razorpay is nobody

        return $hq;
    }

    private function bill(Tenant $hq, string $orderId = 'order_HOOK'): BillingCharge
    {
        $bill = app(BillingEngine::class)->raiseTerm(Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->firstOrFail(), ['via' => 'owner']);
        MoneyWrite::during(fn () => $bill->forceFill(['gateway_order_id' => $orderId])->save());

        return $bill->fresh();
    }

    private function send(string $event, array $payload, ?string $eventId = null)
    {
        [$body, $headers] = FakeRazorpay::webhook($event, $payload, $eventId);

        return $this->call('POST', route('webhooks.razorpay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Razorpay-Signature' => $headers['X-Razorpay-Signature'],
            'HTTP_X-Razorpay-Event-Id' => $headers['X-Razorpay-Event-Id'],
        ], $body);
    }

    private function paymentEntity(string $orderId, string $paymentId = 'pay_HOOK', int $paise = 49900, string $status = 'captured'): array
    {
        return ['payment' => ['entity' => [
            'id' => $paymentId, 'order_id' => $orderId, 'amount' => $paise, 'currency' => 'INR',
            'status' => $status, 'created_at' => now()->timestamp,
        ]]];
    }

    public function test_order_paid_settles_the_bill_it_was_for(): void
    {
        $hq = $this->paying();
        $bill = $this->bill($hq);

        $this->send('order.paid', $this->paymentEntity('order_HOOK'))->assertOk();

        $this->assertSame('paid', $bill->fresh()->status);
        $row = SubscriptionInvoice::withoutGlobalScopes()->sole();
        $this->assertSame('pay_HOOK', $row->razorpay_payment_id);
        $this->assertSame('Gateway (Razorpay)', AdminAuditLog::where('action', 'payment.recorded')->sole()->actorLabel());
    }

    public function test_a_bad_signature_is_refused_and_changes_nothing(): void
    {
        $hq = $this->paying();
        $bill = $this->bill($hq);
        [$body] = FakeRazorpay::webhook('order.paid', $this->paymentEntity('order_HOOK'));

        $this->call('POST', route('webhooks.razorpay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Razorpay-Signature' => hash_hmac('sha256', $body, 'not-the-secret'),
            'HTTP_X-Razorpay-Event-Id' => 'evt_forged',
        ], $body)->assertStatus(400);

        $this->assertSame('pending', $bill->fresh()->status);
        $this->assertSame(0, WebhookEvent::count(), 'a forged event is not even remembered');
    }

    public function test_a_failure_midway_answers_500_and_the_retry_still_settles(): void
    {
        $hq = $this->paying();
        $bill = $this->bill($hq);

        // The first delivery hits an infrastructure failure inside Settlement.
        $this->app->instance(Settlement::class, new class extends Settlement {
            public function __construct() {}
            public function settleGateway(array $payment, string $via, ?string $event = null): array
            {
                throw new \RuntimeException('database went away (simulated)');
            }
        });
        $this->send('order.paid', $this->paymentEntity('order_HOOK'), 'evt_RETRY')->assertStatus(500);

        $this->assertSame(0, WebhookEvent::count(), 'E3-G: not marked handled — or the retry would be skipped');
        $this->assertSame('pending', $bill->fresh()->status);

        // Razorpay retries the same event; this time it works.
        $this->app->forgetInstance(Settlement::class);
        $this->send('order.paid', $this->paymentEntity('order_HOOK'), 'evt_RETRY')->assertOk();

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_the_same_event_twice_is_handled_once(): void
    {
        $hq = $this->paying();
        $this->bill($hq);

        $this->send('order.paid', $this->paymentEntity('order_HOOK'), 'evt_TWICE')->assertOk();
        $this->send('order.paid', $this->paymentEntity('order_HOOK'), 'evt_TWICE')->assertOk();

        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    public function test_money_for_an_unknown_order_is_kept_and_flagged_with_200(): void
    {
        $this->send('order.paid', $this->paymentEntity('order_GHOST', 'pay_GHOST', 120000))->assertOk();

        $kept = UnappliedPayment::sole();
        $this->assertEquals(1200.0, (float) $kept->amount);
        $this->assertSame('order.paid', $kept->gateway_event);
        $this->assertSame(1, AdminAuditLog::where('action', 'payment.unapplied')->count());
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    public function test_a_failed_payment_is_on_the_record_and_changes_nothing(): void
    {
        $hq = $this->paying();
        $bill = $this->bill($hq);
        $payload = $this->paymentEntity('order_HOOK', 'pay_FAIL', status: 'failed');
        $payload['payment']['entity']['error_description'] = 'Payment declined by bank';

        $this->send('payment.failed', $payload)->assertOk();

        $this->assertSame('pending', $bill->fresh()->status, 'they can try again');
        $log = AdminAuditLog::where('action', 'payment.failed')->sole();
        $this->assertStringContainsString('declined by bank', $log->description);
        $this->assertStringContainsString($bill->number, $log->description);
    }

    public function test_an_authorized_payment_waits_for_capture(): void
    {
        $hq = $this->paying();
        $bill = $this->bill($hq);

        $this->send('payment.authorized', $this->paymentEntity('order_HOOK', status: 'authorized'))->assertOk();

        $this->assertSame('pending', $bill->fresh()->status);
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count() + UnappliedPayment::count());
    }

    public function test_a_legacy_autopay_charge_is_kept_on_the_right_customer_and_access_is_untouched(): void
    {
        $hq = $this->paying();
        $sub = Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->firstOrFail();
        \App\Services\SubscriptionLifecycle::writing(fn () => $sub->forceFill(['razorpay_subscription_id' => 'sub_OLD'])->save());
        $before = [$sub->status, $sub->current_period_end->toDateString()];

        $this->send('subscription.charged', [
            'subscription' => ['entity' => ['id' => 'sub_OLD', 'current_end' => now()->addYears(5)->timestamp]],
            'payment' => ['entity' => ['id' => 'pay_OLD', 'amount' => 49900, 'currency' => 'INR', 'created_at' => now()->timestamp]],
        ])->assertOk();
        $this->send('subscription.halted', ['subscription' => ['entity' => ['id' => 'sub_OLD']]])->assertOk();

        $kept = UnappliedPayment::sole();
        $this->assertSame($hq->account_id, $kept->account_id, 'kept against the customer whose autopay took it');
        $fresh = $sub->fresh();
        $this->assertSame($before, [$fresh->status, $fresh->current_period_end->toDateString()]);
        $this->assertSame(1, AdminAuditLog::where('action', 'subscription.gateway_event_ignored')->count());
    }
}
