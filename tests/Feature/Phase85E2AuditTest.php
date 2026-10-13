<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\PaymentRecorder;
use App\Services\Proration;
use App\Services\StoreProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * E2.7 — found while auditing the E2 build (11_E2_PLAN.md §10).
 *
 *   A-1  a renewal taken from a stale page (a second operator / tab) is refused
 *   A-2  "received on" is checked against the BILLING clock, not the app's
 *   A-3  no account, no charge: a clear refusal, never a constraint crash
 *   A-4  a span too long to split is refused, never charged in part
 */
class Phase85E2AuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paying(): Tenant
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $hq = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Audit']);
        $this->sub($hq)->forceFill([
            'status' => 'active', 'interval' => 'monthly',
            'current_period_end' => Carbon::today(config('billing.timezone'))->addDays(20),
        ])->save();

        return $hq;
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    private function pay(Tenant $hq, array $form)
    {
        // Stamped on the TEST clock: A-2 moves it, and a real time() would read as lapsed.
        return $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post(route('superadmin.accounts.payment', $hq->account_id), $form + ['_once' => (string) Str::uuid()]);
    }

    public function test_a1_a_second_renewal_from_a_stale_page_is_refused(): void
    {
        $hq = $this->paying();
        $openedAt = $this->sub($hq)->current_period_end->toDateString();   // what both tabs rendered

        $this->pay($hq, ['for' => 'renewal', 'method' => 'cash', 'renews_from' => $openedAt])->assertSessionHas('status');
        $renewed = $this->sub($hq)->current_period_end->toDateString();

        // The second tab, opened before the first payment: a different form, a fresh token.
        $this->pay($hq, ['for' => 'renewal', 'method' => 'cash', 'renews_from' => $openedAt])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'changed since this page was opened'));

        $this->assertSame(1, SubscriptionInvoice::withoutGlobalScopes()->count(), 'one renewal, not two');
        $this->assertSame($renewed, $this->sub($hq)->current_period_end->toDateString());

        // The preview says so too, before the operator commits.
        $preview = $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('superadmin.accounts.preview', $hq->account_id), [
                'action' => 'record_payment', 'for' => 'renewal', 'method' => 'cash', 'renews_from' => $openedAt,
            ])->json();
        $this->assertStringContainsString('changed since this page was opened', $preview['error']);

        // A fresh page carries the new date and works.
        $this->pay($hq, ['for' => 'renewal', 'method' => 'cash', 'renews_from' => $renewed])->assertSessionHas('status');
        $this->assertSame(2, SubscriptionInvoice::withoutGlobalScopes()->count());
    }

    public function test_a5_reversing_a_branch_payment_never_claims_it_covers_access(): void
    {
        $hq = $this->paying();
        $branch = app(StoreProvisioner::class)->provision(
            User::factory()->create(["tenant_id" => null, "role" => "store_admin"]), ["store_name" => "Audit — Branch 2"], $hq->account->fresh());
        $bill = \App\Models\BillingCharge::where("tenant_id", $branch->id)->sole();
        $branchRow = app(\App\Services\Settlement::class)->settle($bill, ["method" => "cash"]);
        $termRow = app(\App\Services\Settlement::class)->payTerm($this->sub($hq), "renew", ["method" => "cash"]);

        $html = $this->withSession(["auth.password_confirmed_at" => now()->timestamp])
            ->get(route("superadmin.accounts.show", $hq->account_id))->assertOk()->getContent();

        $branchModal = Str::betweenFirst($html, "id=\"m-rev-{$branchRow->id}\"", "</form>");
        $this->assertStringContainsString("Reversing it makes that bill", $branchModal);
        $this->assertStringContainsString("Their access does not change", $branchModal);
        $this->assertStringNotContainsString("currently covers their access", $branchModal);

        $termModal = Str::betweenFirst($html, "id=\"m-rev-{$termRow->id}\"", "</form>");
        $this->assertStringContainsString("currently covers their access", $termModal);
        $this->assertStringContainsString("becomes owed again", $termModal);
    }

    public function test_a6_money_recorded_by_hand_is_only_ever_a_hand_method(): void
    {
        $hq = $this->paying();
        $end = $this->sub($hq)->current_period_end->toDateString();

        // The generic action door (still used for Waive) refuses the other methods…
        foreach (['adjustment', 'razorpay'] as $method) {
            $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->post(route('superadmin.accounts.action', $hq->account_id), [
                    'action' => 'renew', 'method' => $method, '_once' => (string) Str::uuid(),
                ])->assertSessionHasErrors('method');
        }

        // …and so does the engine itself, whichever door is used.
        try {
            app(\App\Services\SubscriptionLifecycle::class)->commit($this->sub($hq), 'renew', ['method' => 'adjustment']);
            $this->fail('an adjustment must not be recorded as a hand payment');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Record cash, UPI', $e->getMessage());
        }

        $this->assertSame(0, SubscriptionInvoice::withoutGlobalScopes()->count());
        $this->assertSame($end, $this->sub($hq)->current_period_end->toDateString(), 'no clock moved');
    }

    public function test_a7_only_one_next_term_is_ever_owed_but_a_reopened_debt_stays(): void
    {
        $hq = $this->paying();
        $engine = app(BillingEngine::class);

        // A reopened term bill: real debt for time already granted.
        $paid = $engine->raiseTerm($this->sub($hq));
        $row = app(\App\Services\Settlement::class)->settle($paid, ['method' => 'cash']);
        app(\App\Services\Settlement::class)->reverse($row, 'bounced');

        // An open quote for the next term; then the clock moves (an extension).
        $first = $engine->raiseTerm($this->sub($hq));
        app(\App\Services\SubscriptionLifecycle::class)->commit($this->sub($hq), 'extend', ['days' => 10, 'reason' => 'outage']);
        $second = $engine->raiseTerm($this->sub($hq));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('superseded', $first->fresh()->status, 'the stale quote is replaced, not left beside the new one');
        $this->assertSame($second->id, $first->fresh()->superseded_by);
        $this->assertSame('pending', $paid->fresh()->status, 'the reopened debt is NOT erased');
        $this->assertEquals(499.0 + 499.0, $engine->owed($hq->account_id), 'one next term + the reopened debt');
    }

    public function test_a8_a_renewal_over_a_scheduled_cancellation_says_it_undoes_it(): void
    {
        $hq = $this->paying();
        app(\App\Services\SubscriptionLifecycle::class)->commit($this->sub($hq), 'cancel', ['reason' => 'closing the shop', 'at_period_end' => true]);

        $preview = app(\App\Services\SubscriptionLifecycle::class)->preview($this->sub($hq), 'record_payment', ['for' => 'renewal', 'method' => 'cash']);

        $this->assertTrue(collect($preview['warnings'])->contains(fn ($w) => str_contains($w, 'undoes that cancellation')));
    }

    public function test_a9_paying_an_open_term_bill_warns_like_a_renewal(): void
    {
        $hq = $this->paying();
        $bill = app(BillingEngine::class)->raiseTerm($this->sub($hq));
        app(\App\Services\SubscriptionLifecycle::class)->commit($this->sub($hq), 'suspend', ['reason' => 'abuse']);

        $preview = app(\App\Services\SubscriptionLifecycle::class)->preview($this->sub($hq), 'record_payment', ['for' => $bill->id, 'method' => 'cash']);

        $this->assertTrue(collect($preview['warnings'])->contains(fn ($w) => str_contains($w, 'replaces the suspension')));
    }

    public function test_a2_received_on_is_judged_on_the_billing_clock(): void
    {
        $hq = $this->paying();
        // 20:00 UTC on the 14th is 01:30 IST on the 15th: the app's "today" (UTC in
        // tests) is still the 14th, the billing day is the 15th.
        Carbon::setTestNow(Carbon::parse('2026-10-14 20:00:00', 'UTC'));
        $this->assertSame('2026-10-15', now(config('billing.timezone'))->toDateString(), 'precondition');

        $this->pay($hq, ['for' => 'renewal', 'method' => 'cash', 'paid_at' => '2026-10-15'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->pay($hq, ['for' => 'renewal', 'method' => 'cash', 'paid_at' => '2026-10-16'])
            ->assertSessionHasErrors('paid_at');
    }

    public function test_a3_no_account_no_charge_and_a_clear_refusal(): void
    {
        $orphan = Tenant::create(['store_name' => 'Pre-backfill']);   // no account: the old path
        $sub = Subscription::withoutGlobalScopes()->where('tenant_id', $orphan->id)->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not linked to a customer account');

        app(BillingEngine::class)->raiseTerm($sub);
    }

    public function test_a4_a_span_too_long_to_split_is_refused_not_charged_in_part(): void
    {
        $hq = $this->paying();
        $sub = $this->sub($hq);
        // A ledger row claiming cover since 1950 (corrupt or imported history).
        app(PaymentRecorder::class)->record($sub, 499, 'cash', [
            'period_start' => Carbon::parse('1950-01-01'), 'period_end' => $sub->current_period_end->copy(),
        ]);

        $this->expectException(\LogicException::class);
        app(Proration::class)->forNewBranch($sub->fresh());
    }
}
