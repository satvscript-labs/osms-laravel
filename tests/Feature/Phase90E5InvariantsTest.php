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
use App\Services\MoneyWriteRefused;
use App\Services\PriceResolver;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeRazorpay;
use Tests\TestCase;

/**
 * E5.4 — the engine's promises, pinned across configurations (06 §1, rules 1–8):
 *
 *   1. one price everywhere: owner page = operator quote = bill = gateway order
 *   2. collected moves only when money is received
 *   3. no money row can ever be deleted
 *   4. every lifecycle action leaves an audit row
 *   5. no billing table cascades from stores (rule 6 / rule 8)
 *   6. every payment the engine records says what it paid for
 */
class Phase90E5InvariantsTest extends TestCase
{
    use RefreshDatabase;

    private FakeRazorpay $rzp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Plan::where('code', 'basic')->update(['monthly_price' => 499, 'yearly_price' => 4999, 'price_tiers' => null]);
        $this->rzp = FakeRazorpay::install($this->app);
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
    }

    /** @return array{0: Tenant, 1: User} */
    private function customer(int $branches = 1, string $interval = 'monthly'): array
    {
        $owner = User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']);
        $hq = app(StoreProvisioner::class)->provision($owner, ['store_name' => 'Invariant HQ']);
        $this->sub($hq)->forceFill(['status' => 'active', 'interval' => $interval,
            'current_period_end' => Carbon::today(config('billing.timezone'))->addDays(5)])->save();

        for ($i = 2; $i <= $branches; $i++) {
            app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin']), ['store_name' => "Invariant B{$i}"], $hq->account->fresh());
        }

        return [$hq, $owner->fresh()];
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    private function collected(): float
    {
        return round((float) SubscriptionInvoice::withoutGlobalScopes()->collected()->sum('amount'), 2);
    }

    /** @return array<string, \Closure(): array{0: Tenant, 1: User}> */
    private function configurations(): array
    {
        return [
            '1 branch, monthly, list' => fn () => $this->customer(1, 'monthly'),
            '3 branches, yearly, list' => fn () => $this->customer(3, 'yearly'),
            'agreed price' => function () {
                [$hq, $owner] = $this->customer(1, 'monthly');
                app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'set_price', ['price' => 399, 'interval' => 'monthly', 'reason' => 'founder']);

                return [$hq, $owner];
            },
            'volume tiers, 3 branches' => function () {
                Plan::where('code', 'basic')->firstOrFail()->update(['price_tiers' => [
                    ['from' => 1, 'to' => 2, 'monthly' => 499, 'yearly' => 4999],
                    ['from' => 3, 'to' => null, 'monthly' => 429, 'yearly' => 4299],
                ]]);

                return $this->customer(3, 'monthly');
            },
            'switching to yearly at renewal' => function () {
                [$hq, $owner] = $this->customer(2, 'monthly');
                app(SubscriptionLifecycle::class)->ownerRequestInterval($this->sub($hq), 'yearly');

                return [$hq, $owner];
            },
        ];
    }

    public function test_one_price_everywhere_owner_page_operator_quote_bill_and_gateway_order(): void
    {
        foreach ($this->configurations() as $label => $make) {
            [$hq, $owner] = $make();
            $sub = $this->sub($hq);
            $next = $sub->pending_interval ?: $sub->interval;

            $ownerPrice = app(PriceResolver::class)->renewalPrice($sub, $next);
            $operatorQuote = app(BillingEngine::class)->quoteTerm($sub)['amount'];

            $this->actingAs($owner)->post(route('tenant.billing.subscribe'), ['interval' => $next])->assertOk();   // the checkout page
            $bill = BillingCharge::where('account_id', $hq->account_id)->pending()->whereIn('kind', ['first_term', 'renewal'])->sole();
            $order = collect($this->rzp->ordersCreated)->firstWhere('charge', $bill->id);

            $this->assertEqualsWithDelta($ownerPrice, $operatorQuote, 0.001, "{$label}: owner page vs operator quote");
            $this->assertEqualsWithDelta($operatorQuote, (float) $bill->amount, 0.001, "{$label}: quote vs bill");
            $this->assertSame((int) round((float) $bill->amount * 100), $order['amount'], "{$label}: bill vs Razorpay order (paise)");

            $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
            Plan::where('code', 'basic')->update(['price_tiers' => null]);
        }
    }

    public function test_collected_moves_only_when_money_is_received(): void
    {
        [$hq] = $this->customer(2);
        $before = $this->collected();

        // Asking for money is not money.
        $bill = app(BillingEngine::class)->raiseTerm($this->sub($hq));
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'set_price', ['price' => 450, 'interval' => 'monthly', 'reason' => 're-quote']);
        app(BillingEngine::class)->requoteOpenTerm($this->sub($hq));
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'comp', ['months' => 1, 'reason' => 'free month']);   // ₹0 row
        $this->assertSame($before, $this->collected(), 'raising, re-quoting, voiding and free months collect nothing');

        // Money received is collected — once; a reversal takes it back out.
        $row = app(Settlement::class)->payTerm($this->sub($hq), 'renew', ['method' => 'cash']);
        $this->assertEqualsWithDelta($before + (float) $row->amount, $this->collected(), 0.001);
        app(Settlement::class)->reverse($row, 'mistyped');
        $this->assertSame($before, $this->collected());

        // Unapplied money is not collected until applied.
        app(Settlement::class)->recordUnapplied(['id' => 'pay_INV', 'order_id' => null, 'amount' => 49900, 'currency' => 'INR',
            'status' => 'captured', 'created_at' => now()->timestamp], null, $hq->account_id, 'test', 'order.paid');
        $this->assertSame($before, $this->collected());
    }

    public function test_no_money_row_can_ever_be_deleted(): void
    {
        [$hq] = $this->customer();
        $row = app(Settlement::class)->payTerm($this->sub($hq), 'renew', ['method' => 'cash']);
        $charge = BillingCharge::findOrFail($row->charge_id);
        $u = app(Settlement::class)->recordUnapplied(['id' => 'pay_DEL', 'order_id' => null, 'amount' => 100, 'currency' => 'INR',
            'status' => 'captured', 'created_at' => now()->timestamp], null, $hq->account_id, 'test', 'order.paid');

        foreach (['ledger row' => $row, 'bill' => $charge, 'unapplied money' => $u] as $what => $model) {
            foreach (['outside the engine' => '$m->delete();', 'even inside the money scope' => '\App\Services\MoneyWrite::during(fn () => $m->delete());'] as $where => $code) {
                try {
                    $this->fromOutsideTests($code, $model->fresh());
                    $this->fail("A {$what} was deleted {$where}.");
                } catch (MoneyWriteRefused) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        $this->assertSame([1, 1, 1], [SubscriptionInvoice::withoutGlobalScopes()->count(), BillingCharge::count(), UnappliedPayment::count()]);
    }

    public function test_every_lifecycle_action_leaves_an_audit_row(): void
    {
        $actions = [
            'extend' => ['days' => 3, 'reason' => 'x'],
            'comp' => ['months' => 1, 'reason' => 'x'],
            'set_price' => ['price' => 399, 'interval' => 'monthly', 'reason' => 'x'],
            'clear_price' => ['reason' => 'x'],
            'switch_interval' => ['interval' => 'yearly', 'reason' => 'x'],
            'suspend' => ['reason' => 'x'],
            'reactivate' => ['reason' => 'x'],
            'cancel' => ['at_period_end' => true, 'reason' => 'x'],
            'force_expire' => ['reason' => 'x'],
            'renew' => ['method' => 'cash'],
            'mark_paid' => ['method' => 'cash', 'amount' => 499, 'reason' => 'x'],
            'waive' => ['reason' => 'x'],
        ];

        foreach ($actions as $action => $input) {
            [$hq] = $this->customer();
            $before = AdminAuditLog::where('action', "subscription.{$action}")->count();

            app(SubscriptionLifecycle::class)->commit($this->sub($hq), $action, $input);

            $this->assertSame($before + 1, AdminAuditLog::where('action', "subscription.{$action}")->count(), "{$action} must leave an audit row");
        }
    }

    public function test_no_billing_table_cascades_from_stores(): void
    {
        $this->assertNotEmpty($this->foreignKeys('billing_charges'), 'the check must see the foreign keys it is checking');

        foreach (['subscription_invoices', 'billing_charges', 'unapplied_payments', 'billing_reminders', 'subscriptions', 'accounts'] as $table) {
            foreach ($this->foreignKeys($table) as [$references, $onDelete]) {
                if ($references === 'tenants') {
                    $this->assertNotSame('CASCADE', strtoupper($onDelete), "{$table} must not be deleted with a store (rule 6)");
                }
            }
        }
    }

    public function test_every_payment_the_engine_records_says_what_it_paid_for(): void
    {
        [$hq] = $this->customer(2);   // a branch bill exists
        $s = app(Settlement::class);
        $s->settle(BillingCharge::where('account_id', $hq->account_id)->where('kind', 'branch_join')->sole(), ['method' => 'upi']);
        $s->payTerm($this->sub($hq), 'renew', ['method' => 'cash', 'cycles' => 2]);
        $u = $s->recordUnapplied(['id' => 'pay_WHAT', 'order_id' => null, 'amount' => (int) round(app(BillingEngine::class)->quoteTerm($this->sub($hq))['amount'] * 100),
            'currency' => 'INR', 'status' => 'captured', 'created_at' => now()->timestamp], null, $hq->account_id, 'test', 'order.paid');
        $s->applyUnappliedToRenewal($u, $this->sub($hq), 'their renewal');

        $money = SubscriptionInvoice::withoutGlobalScopes()->where('account_id', $hq->account_id)->where('amount', '>', 0)->get();
        $this->assertCount(3, $money);
        $this->assertTrue($money->every(fn ($r) => $r->charge_id !== null), 'rule 6: every money row names its bill');
    }

    /** @return list<array{0: string, 1: string}> [referenced table, on-delete rule] */
    private function foreignKeys(string $table): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return array_map(fn ($fk) => [$fk->table, $fk->on_delete], DB::select("PRAGMA foreign_key_list('{$table}')"));
        }

        return array_map(fn ($fk) => [$fk->REFERENCED_TABLE_NAME, $fk->DELETE_RULE], DB::select(
            'SELECT REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        ));
    }

    private function fromOutsideTests(string $body, mixed $model): void
    {
        $file = tempnam(sys_get_temp_dir(), 'e5inv') . '.php';
        file_put_contents($file, "<?php\nreturn function (\$m) {\n{$body}\n};\n");

        try {
            (require $file)($model);
        } finally {
            @unlink($file);
        }
    }
}
