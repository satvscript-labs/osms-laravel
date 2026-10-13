<?php

namespace Tests\Feature;

use App\Models\BillingCharge;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\BillingService;
use App\Services\MoneyWrite;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeRazorpay;
use Tests\TestCase;

/**
 * E3.4 — the owner pays online (14_E3_PLAN.md §5 edges 1–15; E3-A, E3-I, FB-11).
 */
class Phase86E3PayTest extends TestCase
{
    use RefreshDatabase;

    private FakeRazorpay $rzp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->rzp = FakeRazorpay::install($this->app);
    }

    /** @return array{0: Tenant, 1: User} a paying store and its account owner */
    private function customer(string $name = 'Payer', int $daysAhead = 5): array
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $hq = app(StoreProvisioner::class)->provision($owner, ['store_name' => $name]);
        $this->sub($hq)->forceFill([
            'status' => 'active', 'interval' => 'monthly',
            'current_period_end' => Carbon::today(config('billing.timezone'))->addDays($daysAhead),
        ])->save();

        return [$hq, $owner->fresh()];
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    /** An open bill (a new branch's), as the operator's Add a store leaves it. */
    private function openBill(Tenant $hq): BillingCharge
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']), ['store_name' => $hq->store_name . ' — B2'], $hq->account->fresh());
        auth()->logout();

        return BillingCharge::where('account_id', $hq->account_id)->where('kind', 'branch_join')->sole();
    }

    // ------------------------------------------------------------------
    // Opening checkout
    // ------------------------------------------------------------------

    public function test_the_owner_pays_an_open_bill_with_one_order_however_often_they_click(): void
    {
        [$hq, $owner] = $this->customer();
        $bill = $this->openBill($hq);

        $page = $this->actingAs($owner)->post(route('tenant.billing.pay'), ['charge' => $bill->id]);
        $page->assertOk()->assertSee($bill->number)->assertSee('Pay ₹ ' . number_format((float) $bill->amount, 2));

        $this->actingAs($owner)->post(route('tenant.billing.pay'), ['charge' => $bill->id])->assertOk();

        $this->assertCount(1, $this->rzp->ordersCreated, 'E3-A: one order per bill, reused');
        $this->assertSame((int) round((float) $bill->amount * 100), $this->rzp->ordersCreated[0]['amount']);
        $this->assertSame($this->rzp->ordersCreated[0]['order'], $bill->fresh()->gateway_order_id);
        $this->assertTrue($bill->fresh()->isBeingPaidOnline(), 'the window started');
        $this->assertSame('pending', $bill->fresh()->status, 'rule 2: nothing changes until the money lands');
    }

    public function test_who_may_pay_online_is_decided_on_the_server(): void
    {
        [$hq, $owner] = $this->customer();
        $bill = $this->openBill($hq);
        $branchAdmin = User::where('tenant_id', BillingCharge::find($bill->id)->tenant_id)->firstOrFail();

        // A branch's admin — not the payer.
        $this->actingAs($branchAdmin)->post(route('tenant.billing.pay'), ['charge' => $bill->id])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Only the account owner'));

        // Supervised (the platform switch) — refused, not merely hidden.
        PlatformSetting::set(PlatformSetting::SUPERVISED_MODE, true);
        $this->actingAs($owner)->post(route('tenant.billing.pay'), ['charge' => $bill->id])->assertSessionHas('error');
        PlatformSetting::set(PlatformSetting::SUPERVISED_MODE, false);

        // No gateway keys — refused with a sentence for people.
        config(['services.razorpay.key' => null, 'services.razorpay.secret' => null]);
        $this->app->instance(BillingService::class, new BillingService());
        $this->actingAs($owner)->post(route('tenant.billing.pay'), ['charge' => $bill->id])
            ->assertSessionHas('error', fn ($m) => str_contains($m, "Online payment isn't available"));

        $this->assertSame([], $this->rzp->ordersCreated);
    }

    public function test_another_accounts_bill_never_resolves(): void
    {
        [, $owner] = $this->customer('Mine');
        [$theirs] = $this->customer('Theirs');
        $bill = $this->openBill($theirs);

        $this->actingAs($owner)->post(route('tenant.billing.pay'), ['charge' => $bill->id])->assertNotFound();
        $this->assertSame([], $this->rzp->ordersCreated);
    }

    public function test_a_bill_already_paid_cannot_be_paid_again(): void
    {
        [$hq, $owner] = $this->customer();
        $bill = $this->openBill($hq);
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        app(\App\Services\Settlement::class)->settle($bill, ['method' => 'cash']);

        $this->actingAs($owner)->post(route('tenant.billing.pay'), ['charge' => $bill->id])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'nothing to pay'));
        $this->assertSame([], $this->rzp->ordersCreated);
    }

    public function test_a_locked_out_owner_can_still_pay_their_way_back(): void
    {
        [$hq, $owner] = $this->customer('Lapsed', daysAhead: -20);
        \App\Services\SubscriptionLifecycle::writing(fn () => $this->sub($hq)->forceFill(['status' => 'past_due'])->save());
        $this->assertSame('locked', $this->sub($hq)->accessState());

        $this->actingAs($owner)->post(route('tenant.billing.subscribe'), ['interval' => 'monthly'])->assertOk();

        $this->assertCount(1, $this->rzp->ordersCreated);
    }

    public function test_when_the_gateway_is_down_the_owner_gets_a_sentence_not_a_stack_trace(): void
    {
        [$hq, $owner] = $this->customer();
        $bill = $this->openBill($hq);
        $this->rzp->down = true;

        $this->actingAs($owner)->post(route('tenant.billing.pay'), ['charge' => $bill->id])
            ->assertSessionHas('error', 'We could not reach the payment service just now. Nothing was charged — please try again in a few minutes.');

        $fresh = $bill->fresh();
        $this->assertNull($fresh->gateway_order_id, 'nothing half-done');
        $this->assertNull($fresh->gateway_checkout_at, 'and no window blocks the operator');
    }

    // ------------------------------------------------------------------
    // The callback
    // ------------------------------------------------------------------

    public function test_a_good_callback_settles_the_bill_and_thanks_them_with_the_receipt(): void
    {
        [$hq, $owner] = $this->customer();
        $bill = $this->openBill($hq);
        $this->actingAs($owner)->post(route('tenant.billing.pay'), ['charge' => $bill->id]);
        $order = $bill->fresh()->gateway_order_id;
        $pay = $this->rzp->pay($order, (float) $bill->amount);

        $this->actingAs($owner)->post(route('tenant.billing.paid'), FakeRazorpay::callback($order, $pay))
            ->assertRedirect(route('tenant.billing.index'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Payment received') && str_contains($m, 'OSMS-'));

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame($pay, SubscriptionInvoice::withoutGlobalScopes()->sole()->razorpay_payment_id);
    }

    public function test_a_forged_or_mismatched_callback_records_nothing(): void
    {
        [$hq, $owner] = $this->customer();
        $bill = $this->openBill($hq);
        $this->actingAs($owner)->post(route('tenant.billing.pay'), ['charge' => $bill->id]);
        $order = $bill->fresh()->gateway_order_id;
        $pay = $this->rzp->pay($order, (float) $bill->amount);

        // A forged signature.
        $this->actingAs($owner)->post(route('tenant.billing.paid'), ['razorpay_signature' => str_repeat('a', 64)] + FakeRazorpay::callback($order, $pay))
            ->assertSessionHas('error');

        // A correctly signed pair whose payment belongs to ANOTHER order.
        $other = $this->rzp->pay('order_SOMEONE_ELSE', 1);
        $this->actingAs($owner)->post(route('tenant.billing.paid'), FakeRazorpay::callback($order, $other))
            ->assertSessionHas('error');

        $this->assertSame('pending', $bill->fresh()->status);
        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());
        $this->assertSame(0, \App\Models\UnappliedPayment::count(), 'a mismatched pair is refused, not recorded as anyone\x27s money');
    }

    public function test_one_owner_cannot_confirm_another_accounts_order(): void
    {
        [$hq] = $this->customer('Theirs');
        [, $intruder] = $this->customer('Mine');
        $bill = $this->openBill($hq);
        $order = app(BillingEngine::class)->openCheckout($bill, fn () => 'order_THEIRS')->gateway_order_id;
        $pay = $this->rzp->pay($order, (float) $bill->amount);

        $this->actingAs($intruder)->post(route('tenant.billing.paid'), FakeRazorpay::callback($order, $pay))
            ->assertSessionHas('error');

        $this->assertSame('pending', $bill->fresh()->status, 'their webhook settles it, not my browser');
    }

    public function test_when_the_bank_is_slow_the_callback_waits_and_the_webhook_settles_once(): void
    {
        [$hq, $owner] = $this->customer();
        $bill = $this->openBill($hq);
        $this->actingAs($owner)->post(route('tenant.billing.pay'), ['charge' => $bill->id]);
        $order = $bill->fresh()->gateway_order_id;
        $pay = $this->rzp->pay($order, (float) $bill->amount, 'authorized');

        $this->actingAs($owner)->post(route('tenant.billing.paid'), FakeRazorpay::callback($order, $pay))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'confirming your payment'));
        $this->assertSame('pending', $bill->fresh()->status);

        // Captured a moment later: the webhook settles it — and a late, repeated callback does nothing more.
        $this->rzp->payments[$pay]['status'] = 'captured';
        [$body, $headers] = FakeRazorpay::webhook('payment.captured', ['payment' => ['entity' => $this->rzp->payments[$pay]]]);
        $this->call('POST', route('webhooks.razorpay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Razorpay-Signature' => $headers['X-Razorpay-Signature'],
            'HTTP_X-Razorpay-Event-Id' => $headers['X-Razorpay-Event-Id'],
        ], $body)->assertOk();
        $this->actingAs($owner)->post(route('tenant.billing.paid'), FakeRazorpay::callback($order, $pay));

        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    public function test_if_razorpay_cannot_be_reached_at_callback_nothing_is_lost(): void
    {
        [$hq, $owner] = $this->customer();
        $bill = $this->openBill($hq);
        $this->actingAs($owner)->post(route('tenant.billing.pay'), ['charge' => $bill->id]);
        $order = $bill->fresh()->gateway_order_id;
        $pay = $this->rzp->pay($order, (float) $bill->amount);
        $this->rzp->down = true;

        $this->actingAs($owner)->post(route('tenant.billing.paid'), FakeRazorpay::callback($order, $pay))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'confirming your payment'));

        $this->assertSame('pending', $bill->fresh()->status, 'the webhook will confirm it; nothing guessed from the browser');
    }
}
