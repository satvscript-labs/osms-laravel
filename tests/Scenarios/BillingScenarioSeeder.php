<?php

namespace Tests\Scenarios;

use App\Models\Account;
use App\Models\BillingCharge;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\RenewalClock;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * LOCAL TEST DATA for billing E1–E4: one customer per scenario, every one named for
 * what it tests (E{n}_<what>_test), built through the real engine — the same doors
 * an operator, an owner, Razorpay and the morning clock use — so the audit trail,
 * the bills and the ledger are what production would hold.
 *
 *   php artisan db:seed --class="Tests\Scenarios\BillingScenarioSeeder"
 *
 * Re-running it skips scenarios that already exist. LOCAL ONLY: it refuses to run
 * anywhere but local / testing, and lives under tests/ so a production install
 * (composer --no-dev) cannot even load it.
 *
 * Every scenario login (owners, branch admin, staff, the scenario operator) uses
 * the password below — a local test value, never a real one.
 *
 * Guide: _artifacts/platform/feat-billing/19_SCENARIO_DATA.md
 */
class BillingScenarioSeeder extends Seeder
{
    /** The one password for every scenario login (local test data only). */
    public const PASSWORD = 'Scenario-Test-2026!';

    public const OPERATOR_EMAIL = 'scenario.operator@scenario.test';

    private Carbon $today;

    /** @var list<array{scenario: string, login: string, check: string}> */
    private array $made = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('BillingScenarioSeeder is local test data — it only runs with APP_ENV=local or testing.');
        }

        if (! Plan::query()->where('code', 'basic')->exists()) {
            $this->call(\Database\Seeders\PlanSeeder::class);
        }

        $this->today = Carbon::today(config('billing.timezone', 'Asia/Kolkata'));

        $operator = User::query()->firstOrCreate(['email' => self::OPERATOR_EMAIL], [
            'name' => 'Scenario Operator',
            'password' => Hash::make(self::PASSWORD),
            'role' => 'superadmin',
            'tenant_id' => null,
            'email_verified_at' => now(),
        ]);
        Auth::setUser($operator);

        $this->e1();
        $this->e2();
        $this->e3();
        $this->e4();
        $this->e5();

        // The morning clock, once, as it would run today: renewals due within 7 days get
        // their bills (reminder EMAILS follow the Platform switch — off unless you turned it on).
        app(RenewalClock::class)->run();

        Auth::forgetUser();

        $this->command?->table(['Scenario', 'Log in as', 'What to check'], array_map(fn ($m) => array_values($m), $this->made));
        $this->command?->info('Operator: ' . self::OPERATOR_EMAIL . ' — every scenario login uses the password in ' . self::class . '.');
    }

    // ------------------------------------------------------------------
    // E1 — the guard and the clock
    // ------------------------------------------------------------------

    private function e1(): void
    {
        $this->scenario('E1_paid_monthly_active_test', 'e1.paid.owner', 'Active, paid by cash; Ledger has the receipt; next billing a month from today', function (Tenant $hq) {
            $this->set($hq, ['status' => 'active', 'interval' => 'monthly', 'current_period_end' => $this->day(-10)]);
            app(Settlement::class)->payTerm($this->sub($hq), 'renew', ['method' => 'cash', 'reference' => 'E1-CASH-1']);
        });

        $this->scenario('E1_trial_running_test', 'e1.trial.owner', 'On trial, 10 days left; trial banner; "Start your paid plan"', function (Tenant $hq) {
            $this->set($hq, ['current_period_end' => $this->day(10)]);
        });

        $this->scenario('E1_trial_ended_test', 'e1.trialended.owner', 'Trial ended (System); store locked; owner lands on Subscription to subscribe', function (Tenant $hq) {
            $this->set($hq, ['current_period_end' => $this->day(-1)]);
            app(SubscriptionLifecycle::class)->expireTrial($this->sub($hq));
        });

        $this->scenario('E1_pending_switch_to_yearly_test', 'e1.switch.owner', 'Monthly now, switching to yearly at the next renewal (both pages say so)', function (Tenant $hq) {
            $this->paidMonthly($hq, 15);
            app(SubscriptionLifecycle::class)->ownerRequestInterval($this->sub($hq), 'yearly');
        });

        $this->scenario('E1_suspended_by_operator_test', 'e1.suspended.owner', 'Suspended (not "overdue"); owner sees the paused page, no Pay', function (Tenant $hq) {
            $this->paidMonthly($hq, 12);
            app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'suspend', ['reason' => 'Scenario: dispute about a refund']);
        });

        $this->scenario('E1_cancelled_now_by_operator_test', 'e1.cancelled.owner', 'Cancelled by you; owner cannot pay their way back (lock page)', function (Tenant $hq) {
            $this->paidMonthly($hq, 12);
            app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'cancel', ['reason' => 'Scenario: closed the business']);
        });

        $this->scenario('E1_negotiated_price_399_test', 'e1.agreed.owner', 'Agreed price ₹399/mo; owner page says "your agreed price"; Plans lists it as bespoke', function (Tenant $hq) {
            $this->paidMonthly($hq, 18);
            app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'set_price', ['price' => 399, 'interval' => 'monthly', 'reason' => 'Scenario: founding customer']);
        });
    }

    // ------------------------------------------------------------------
    // E2 — bills and payments
    // ------------------------------------------------------------------

    private function e2(): void
    {
        $this->scenario('E2_branch_join_bill_owed_test', 'e2.branch.owner', 'Two stores; Owed tab: a part-period bill for the branch, with its working; branch admin: e2.branch.admin', function (Tenant $hq) {
            $this->paidMonthly($hq, 20);
            $this->user('e2.branch.admin', 'E2 Branch Admin', 'store_admin', null, function (User $admin) use ($hq) {
                app(StoreProvisioner::class)->provision($admin, ['store_name' => 'E2_branch_join_bill_owed_test — Branch 2'], $hq->account->fresh());
            });
        });

        $this->scenario('E2_reversed_payment_reopened_test', 'e2.reversed.owner', 'Ledger: a struck-through receipt; Owed: the same bill owed again; access unchanged', function (Tenant $hq) {
            $this->set($hq, ['status' => 'active', 'interval' => 'monthly', 'current_period_end' => $this->day(5)]);
            $row = app(Settlement::class)->payTerm($this->sub($hq), 'renew', ['method' => 'cheque', 'reference' => 'E2-CHQ-BOUNCED']);
            app(Settlement::class)->reverse($row, 'Scenario: the cheque bounced');
        });

        $this->scenario('E2_paid_3_months_ahead_test', 'e2.ahead.owner', 'One payment covering 3 months; covered until ~3 months out', function (Tenant $hq) {
            $this->set($hq, ['status' => 'active', 'interval' => 'monthly', 'current_period_end' => $this->day(2)]);
            app(Settlement::class)->payTerm($this->sub($hq), 'renew', ['method' => 'upi', 'reference' => 'E2-UPI-3M', 'cycles' => 3]);
        });

        $this->scenario('E2_overdue_in_grace_test', 'e2.grace.owner', 'Ended 3 days ago, still working (grace); amber banner with the date it stops', function (Tenant $hq) {
            $this->set($hq, ['status' => 'active', 'interval' => 'monthly', 'current_period_end' => $this->day(-3)]);
        });

        $this->scenario('E2_lapsed_past_due_test', 'e2.lapsed.owner', 'Lapsed (System) to past due, store locked; the renewal bill says what restores them', function (Tenant $hq) {
            $this->set($hq, ['status' => 'active', 'interval' => 'monthly', 'current_period_end' => $this->day(-12)]);
            app(SubscriptionLifecycle::class)->lapse($this->sub($hq));
            app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'system', 'actor' => 'system']);
        });
    }

    // ------------------------------------------------------------------
    // E3 — the owner pays online; money on no bill
    // ------------------------------------------------------------------

    private function e3(): void
    {
        $this->scenario('E3_paid_online_test', 'e3.online.owner', 'Ledger: ₹499 · Razorpay, ref order_E3_paid_online_test; Audit: "Renewed … — paid online"', function (Tenant $hq) {
            $this->set($hq, ['status' => 'active', 'interval' => 'monthly', 'current_period_end' => $this->day(4)]);
            $this->payOnline($hq, 'E3_paid_online_test');
        });

        $this->scenario('E3_reversed_online_payment_test', 'e3.reversedonline.owner', 'Online payment reversed: bill owed again with NO old order — Pay opens a fresh one (F1)', function (Tenant $hq) {
            $this->set($hq, ['status' => 'active', 'interval' => 'monthly', 'current_period_end' => $this->day(6)]);
            $row = $this->payOnline($hq, 'E3_reversed_online_payment_test');
            app(Settlement::class)->reverse($row, 'Scenario: refunded in the Razorpay dashboard');
        });

        $this->scenario('E3_amount_mismatch_unapplied_test', 'e3.mismatch.owner', 'Paid ₹300 against a ₹499 bill: kept as "received online · not on a bill" on their Owed tab', function (Tenant $hq) {
            $this->set($hq, ['status' => 'active', 'interval' => 'monthly', 'current_period_end' => $this->day(9)]);
            $bill = app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'owner']);
            $bill = app(BillingEngine::class)->openCheckout($bill, fn () => 'order_E3_amount_mismatch_unapplied_test');
            app(Settlement::class)->settleGateway($this->payment('E3_amount_mismatch_unapplied_test', $bill->gateway_order_id, 300), 'webhook', 'order.paid');
        });

        $this->scenario('E3_unapplied_known_customer_test', 'e3.known.owner', 'Old autopay money (₹499) on their account: Apply → "Their next renewal"', function (Tenant $hq) {
            $this->paidMonthly($hq, 11);
            app(Settlement::class)->recordUnapplied($this->payment('E3_unapplied_known_customer_test', null, 499), null, $hq->account_id,
                'Scenario: charged by an old Razorpay autopay — OSMS no longer bills by autopay.', 'subscription.charged');
        });

        // Not a customer: money for an order OSMS has no bill for — "Whose is it?" on Billing.
        if (! \App\Models\UnappliedPayment::query()->where('gateway_payment_id', 'pay_E3_unapplied_unknown_customer_test')->exists()) {
            app(Settlement::class)->recordUnapplied($this->payment('E3_unapplied_unknown_customer_test', 'order_E3_unknown', 1200), null, null,
                'Scenario: paid online for order order_E3_unknown, which OSMS has no bill for.', 'order.paid');
            $this->made[] = ['scenario' => 'E3_unapplied_unknown_customer_test', 'login' => self::OPERATOR_EMAIL,
                'check' => 'Today banner + Billing: ₹1,200 "Customer unknown" → Whose is it? → refund or apply'];
        }
    }

    // ------------------------------------------------------------------
    // E4 — the clock and reminders
    // ------------------------------------------------------------------

    private function e4(): void
    {
        $this->scenario('E4_renewal_due_in_7_days_test', 'e4.due7.owner', 'Bill raised by System (Owed: "due <date>", not "owed"); blue banner + Pay now; staff: e4.due7.staff sees nothing yet', function (Tenant $hq) {
            $this->paidMonthly($hq, 7);
            $this->user('e4.due7.staff', 'E4 Due7 Staff', 'staff', $hq->id);
        });

        $this->scenario('E4_renewal_due_tomorrow_test', 'e4.due1.owner', 'Banner "due tomorrow"; morning run would send the due-tomorrow email', function (Tenant $hq) {
            $this->paidMonthly($hq, 1);
        });

        $this->scenario('E4_renewal_due_today_test', 'e4.due0.owner', 'Banner "due today"', function (Tenant $hq) {
            $this->paidMonthly($hq, 0);
        });

        $this->scenario('E4_free_period_ends_tomorrow_test', 'e4.free.owner', 'Free months end tomorrow: never billed ahead; banner + "free period ends tomorrow" email (A-22); Today: "Free period ends — decide"', function (Tenant $hq) {
            $this->paidMonthly($hq, 20);
            app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'comp', ['months' => 1, 'reason' => 'Scenario: launch offer']);
            $this->set($hq, ['current_period_end' => $this->day(1), 'override_until' => $this->day(1)]);
        });

        $this->scenario('E4_cancels_at_period_end_test', 'e4.leaving.owner', 'Owner cancelled: no renewal bill, no reminders; after the end date reconcile makes it Cancelled (not overdue, F8)', function (Tenant $hq) {
            $this->paidMonthly($hq, 5);
            app(SubscriptionLifecycle::class)->ownerCancelAtPeriodEnd($this->sub($hq));
        });

        $this->scenario('E4_renewal_voided_by_operator_test', 'e4.voided.owner', 'You voided the renewal: the morning run never raises it again this cycle (A-16)', function (Tenant $hq) {
            $this->paidMonthly($hq, 5);
            app(BillingEngine::class)->void(app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'system']), 'Scenario: they pay by cheque on the day');
        });

        $this->scenario('E4_supervised_billed_by_hand_test', 'e4.byhand.owner', 'Billed by hand: banner "Our team handles your billing", no Pay; email has no Pay button', function (Tenant $hq) {
            $this->paidMonthly($hq, 4);
            $hq->account->update(['supervised' => true, 'supervised_reason' => 'Scenario: pays by bank transfer every month']);
        });

        $this->scenario('E4_has_whatsapp_phone_test', 'e4.phone.owner', 'Today row: "Nudge" — WhatsApp with the message written (fictional number)', function (Tenant $hq) {
            $this->paidMonthly($hq, 6);
            $hq->account->update(['billing_phone' => '98765 43210']);
        });

        $this->scenario('E4_month_end_31st_test', 'e4.monthend.owner', 'Renews on the 31st: Record payment → next renewal previews the month\'s last day, then back to the 31st (FB-32)', function (Tenant $hq) {
            $end = $this->today->copy()->addDays(8);
            while ($end->day !== 31) {
                $end->addDay();
            }
            $this->set($hq, ['status' => 'active', 'interval' => 'monthly', 'current_period_end' => $end->toDateString(), 'billing_anchor_day' => 31]);
        });
    }

    // ------------------------------------------------------------------
    // E5 — polish: closing branches, re-matching money, trial emails
    // ------------------------------------------------------------------

    private function e5(): void
    {
        $this->scenario('E5_close_one_of_two_branches_test', 'e5.closebranch.owner', 'Close the Branch 2 store: the dialog shows ₹ 998 → ₹ 499 from the next renewal and the open renewal bill re-priced (FB-14)', function (Tenant $hq) {
            $this->paidMonthly($hq, 6);
            $this->user('e5.closebranch.admin', 'E5 Close Branch Admin', 'store_admin', null, function (User $admin) use ($hq) {
                app(StoreProvisioner::class)->provision($admin, ['store_name' => 'E5_close_one_of_two_branches_test — Branch 2'], $hq->account->fresh());
            });
            app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'system']);
        });

        $this->scenario('E5_close_last_store_test', 'e5.closelast.owner', 'Close their only store: "This is their last open store" + Cancel the customer instead (never automatic)', function (Tenant $hq) {
            $this->paidMonthly($hq, 18);
        });

        $this->scenario('E5_unapplied_on_wrong_customer_test', 'e5.wrongcustomer.owner', '₹ 499 received online was matched here by mistake: Billing → Wrong customer? → move it to E5_close_last_store_test (reason required)', function (Tenant $hq) {
            $this->paidMonthly($hq, 14);
            app(Settlement::class)->recordUnapplied($this->payment('E5_unapplied_on_wrong_customer_test', null, 499), null, $hq->account_id,
                'Scenario: matched to this customer by mistake — it belongs to E5_close_last_store_test.', 'order.paid');
        });

        $this->scenario('E5_trial_ends_in_3_days_test', 'e5.trial3.owner', '`php artisan subscriptions:reconcile` emails "trial ends in 3 days" once — run it twice, still one (E5-C)', function (Tenant $hq) {
            $this->set($hq, ['current_period_end' => $this->day(3)]);
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function scenario(string $name, string $login, string $check, \Closure $build): void
    {
        if (Account::query()->where('display_name', $name)->exists()) {
            $this->made[] = ['scenario' => $name, 'login' => "{$login}@scenario.test", 'check' => '(already there — skipped)'];

            return;
        }

        $owner = User::query()->create([
            'name' => str($login)->before('.owner')->replace('.', ' ')->title() . ' Owner',
            'email' => "{$login}@scenario.test",
            'password' => Hash::make(self::PASSWORD),
            'role' => 'store_admin',
            'tenant_id' => null,
            'email_verified_at' => now(),
        ]);

        $hq = app(StoreProvisioner::class)->provision($owner, ['store_name' => $name]);
        $hq->account->update(['display_name' => $name]);

        $build($hq->fresh());

        $this->made[] = ['scenario' => $name, 'login' => $owner->email, 'check' => $check];
    }

    private function user(string $login, string $name, string $role, ?string $tenantId, ?\Closure $then = null): User
    {
        $user = User::query()->firstOrCreate(['email' => "{$login}@scenario.test"], [
            'name' => $name,
            'password' => Hash::make(self::PASSWORD),
            'role' => $role,
            'tenant_id' => $tenantId,
            'email_verified_at' => now(),
        ]);

        if ($then && $user->wasRecentlyCreated) {
            $then($user);
        }

        return $user;
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    /** Stage dates and status the way a real history would have left them (local fixture only). */
    private function set(Tenant $of, array $fields): void
    {
        SubscriptionLifecycle::writing(fn () => $this->sub($of)->forceFill($fields)->save());
    }

    /** A paying monthly customer whose paid month ends in $daysLeft days (bought by cash, on the ledger). */
    private function paidMonthly(Tenant $hq, int $daysLeft): void
    {
        $paidThrough = $this->today->copy()->addDays($daysLeft);
        $this->set($hq, ['status' => 'active', 'interval' => 'monthly',
            'current_period_end' => $paidThrough->copy()->subMonthNoOverflow()->max($this->today)->toDateString()]);
        app(Settlement::class)->payTerm($this->sub($hq), 'renew', ['method' => 'cash', 'reference' => 'SCENARIO-CASH']);
        // Move the whole period, not just its end: a branch bill's working reads "start → end" from both.
        $this->set($hq, ['current_period_start' => $paidThrough->copy()->subMonthNoOverflow()->toDateString(),
            'current_period_end' => $paidThrough->toDateString(), 'override_until' => $paidThrough->toDateString()]);
    }

    /** The owner pays online through the real gateway path (a simulated, captured Razorpay payment). */
    private function payOnline(Tenant $hq, string $tag): \App\Models\SubscriptionInvoice
    {
        $bill = app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'owner']);
        $bill = app(BillingEngine::class)->openCheckout($bill, fn () => "order_{$tag}");
        $result = app(Settlement::class)->settleGateway($this->payment($tag, $bill->gateway_order_id, (float) $bill->amount), 'callback');

        return $result['row'];
    }

    private function payment(string $tag, ?string $orderId, float $rupees): array
    {
        return ['id' => "pay_{$tag}", 'order_id' => $orderId, 'amount' => (int) round($rupees * 100),
            'currency' => 'INR', 'status' => 'captured', 'created_at' => now()->timestamp];
    }

    private function day(int $offset): string
    {
        return $this->today->copy()->addDays($offset)->toDateString();
    }
}
