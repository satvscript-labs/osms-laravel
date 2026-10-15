<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\UnappliedPayment;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\PaymentRecorder;
use App\Services\PriceResolver;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * E5.2 — re-matching money (E5-B), numbers that never clash (E5-E), and the owner
 * page saying "can't pay online" once (E5-D).
 */
class Phase90E5LeversTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Plan::where('code', 'basic')->update(['monthly_price' => 499, 'yearly_price' => 4999, 'price_tiers' => null]);
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
    }

    /** @return array{0: Tenant, 1: User} */
    private function customer(string $name): array
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $hq = app(StoreProvisioner::class)->provision($owner, ['store_name' => $name]);
        Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->firstOrFail()
            ->forceFill(['status' => 'active', 'interval' => 'monthly', 'current_period_end' => Carbon::today(config('billing.timezone'))->addDays(5)])->save();

        return [$hq, $owner->fresh()];
    }

    private function money(?Tenant $of, string $id = 'pay_RM'): UnappliedPayment
    {
        return app(Settlement::class)->recordUnapplied(['id' => $id, 'order_id' => null, 'amount' => 49900, 'currency' => 'INR',
            'status' => 'captured', 'created_at' => now()->timestamp], null, $of?->account_id, 'test', 'order.paid');
    }

    private function op(): self
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_money_on_the_wrong_customer_is_moved_with_a_reason_and_a_trail(): void
    {
        [$wrong] = $this->customer('Wrong Optical');
        [$right] = $this->customer('Right Optical');
        $u = $this->money($wrong);

        $this->op()->get(route('superadmin.billing.index'))->assertSee('Wrong customer?');

        $this->op()->post(route('superadmin.unapplied.rematch', $u->id), ['account' => $right->account_id, '_once' => (string) Str::uuid()])
            ->assertSessionHasErrors('note');
        $this->op()->post(route('superadmin.unapplied.rematch', $u->id), [
            'account' => $right->account_id, 'note' => 'receipt is from the other shop', '_once' => (string) Str::uuid(),
        ])->assertRedirect(route('superadmin.accounts.show', $right->account_id) . '#tab-owed');

        $this->assertSame($right->account_id, $u->fresh()->account_id);
        $log = AdminAuditLog::where('action', 'payment.unapplied_rematched')->sole();
        $this->assertSame([$wrong->account_id, $right->account_id], [$log->meta['from_account_id'], $log->meta['account_id']]);
    }

    public function test_only_open_matched_money_can_be_moved(): void
    {
        [$a] = $this->customer('A Optical');
        [$b] = $this->customer('B Optical');
        $s = app(Settlement::class);

        $refuse = function (UnappliedPayment $u, string $to, string $why) use ($s) {
            try {
                $s->rematchUnapplied($u, $to, 'x');
                $this->fail("must refuse: {$why}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        };

        $refuse($this->money(null, 'pay_NOBODY'), $b->account_id, 'not matched yet — use Whose is it?');
        $refuse($this->money($a, 'pay_SAME'), $a->account_id, 'already there');

        $refunded = $this->money($a, 'pay_REFUNDED');
        $s->markRefunded($refunded, 'rfnd_1');
        $refuse($refunded->fresh(), $b->account_id, 'refunded');

        $applied = $this->money($a, 'pay_APPLIED');
        $s->applyUnappliedToRenewal($applied, Subscription::withoutGlobalScopes()->where('account_id', $a->account_id)->firstOrFail(), 'their renewal');
        $refuse($applied->fresh(), $b->account_id, 'applied');
    }

    public function test_a_bill_number_taken_a_moment_ago_is_not_reused(): void
    {
        [$a] = $this->customer('Clash A');
        [$b] = $this->customer('Clash B');
        $first = app(BillingEngine::class)->raiseTerm(Subscription::withoutGlobalScopes()->where('account_id', $a->account_id)->firstOrFail());

        // The next "latest number" read happens before the other insert lands: it mints the same number once.
        $this->app->instance(BillingEngine::class, new class(app(PriceResolver::class), $first->number) extends BillingEngine {
            private bool $raced = false;

            public function __construct(PriceResolver $prices, private string $taken) { parent::__construct($prices); }

            protected function nextNumber(): string
            {
                if (! $this->raced) {
                    $this->raced = true;

                    return $this->taken;
                }

                return parent::nextNumber();
            }
        });

        $second = app(BillingEngine::class)->raiseTerm(Subscription::withoutGlobalScopes()->where('account_id', $b->account_id)->firstOrFail());

        $this->assertNotSame($first->number, $second->number);
        $this->assertSame(2, BillingCharge::count());
    }

    public function test_a_receipt_number_taken_a_moment_ago_is_not_reused(): void
    {
        [$a] = $this->customer('Receipt A');
        [$b] = $this->customer('Receipt B');
        $first = app(Settlement::class)->payTerm(Subscription::withoutGlobalScopes()->where('account_id', $a->account_id)->firstOrFail(), 'renew', ['method' => 'cash']);

        $this->app->instance(PaymentRecorder::class, new class($first->receipt_no) extends PaymentRecorder {
            private bool $raced = false;

            public function __construct(private string $taken) {}

            protected function nextReceiptNo(): string
            {
                if (! $this->raced) {
                    $this->raced = true;

                    return $this->taken;
                }

                return parent::nextReceiptNo();
            }
        });
        $this->app->forgetInstance(Settlement::class);
        $this->app->forgetInstance(\App\Services\SubscriptionLifecycle::class);

        $second = app(Settlement::class)->payTerm(Subscription::withoutGlobalScopes()->where('account_id', $b->account_id)->firstOrFail(), 'renew', ['method' => 'cash']);

        $this->assertNotSame($first->receipt_no, $second->receipt_no);
        $this->assertSame(2, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    public function test_the_owner_page_says_it_cannot_take_payment_online_once(): void
    {
        [$hq, $owner] = $this->customer('Once Optical');
        app(BillingEngine::class)->raiseTerm(Subscription::withoutGlobalScopes()->where('account_id', $hq->account_id)->firstOrFail());

        // No gateway keys in the test environment: online payment is unavailable.
        $html = $this->actingAs($owner)->get(route('tenant.billing.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, "Online payment isn't available right now") + substr_count($html, 'Online payment isn&#039;t available right now'));
        $this->assertStringContainsString('Bills to pay', $html, 'the bill is still listed, without a Pay button');
    }
}
