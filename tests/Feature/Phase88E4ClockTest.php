<?php

namespace Tests\Feature;

use App\Mail\RenewalReminderMail;
use App\Models\AdminAuditLog;
use App\Models\BillingCharge;
use App\Models\BillingReminder;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\PriceResolver;
use App\Services\Settlement;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * E4.3 / E4.4 — the daily clock: bills raised 7 days ahead, reminders once per
 * stage per cycle (16_E4_PLAN.md §5, edges 1–4, 10–20).
 */
class Phase88E4ClockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Plan::where('code', 'basic')->update(['monthly_price' => 499, 'yearly_price' => 4999, 'price_tiers' => null]);
        Mail::fake();
        PlatformSetting::set(PlatformSetting::RENEWAL_REMINDERS, true);
        $this->on('2026-11-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function on(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date . ' 08:00:00', config('billing.timezone')));
    }

    private function customer(string $endsOn, string $name = 'Clock Optical', string $email = 'owner@clock.test'): Tenant
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        $hq = app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin', 'email' => $email]), ['store_name' => $name]);
        $this->sub($hq)->forceFill(['status' => 'active', 'interval' => 'monthly', 'current_period_end' => $endsOn])->save();
        auth()->logout();   // the clock runs as nobody

        return $hq;
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    private function clock(bool $dry = false): string
    {
        Artisan::call('billing:daily', $dry ? ['--dry-run' => true] : []);

        return Artisan::output();
    }

    private function open(Tenant $hq): ?BillingCharge
    {
        return BillingCharge::where('account_id', $hq->account_id)->pending()->whereIn('kind', ['first_term', 'renewal'])->first();
    }

    /** @return list<string> stages queued so far, in order */
    private function stagesSent(): array
    {
        return Mail::queued(RenewalReminderMail::class)->map(fn ($m) => $m->stage)->values()->all();
    }

    public function test_seven_days_ahead_the_bill_is_raised_and_the_owner_reminded_once(): void
    {
        $hq = $this->customer('2026-11-08');

        $this->clock();
        $this->clock();   // the same morning again

        $bill = $this->open($hq);
        $this->assertNotNull($bill);
        $this->assertSame(['renewal', 'system', '2026-11-08'], [$bill->kind, $bill->raised_via, $bill->period_start->toDateString()]);
        $this->assertEquals(499, (float) $bill->amount);
        $this->assertSame(1, BillingCharge::where('account_id', $hq->account_id)->count(), 'one bill, however often it runs');
        $this->assertSame('System', AdminAuditLog::where('action', 'charge.raised')->sole()->actorLabel());

        $this->assertSame(['due_7'], $this->stagesSent(), 'one email, however often it runs');
        Mail::assertQueued(RenewalReminderMail::class, fn ($m) => $m->hasTo('owner@clock.test') && $m->billNumber === $bill->number);
        $this->assertSame(1, BillingReminder::count());
        $this->assertSame('access', $this->sub($hq)->accessState() === 'active' ? 'access' : 'changed', 'raising a bill changes nothing about access (rule 2)');
    }

    public function test_eight_days_ahead_nothing_happens_yet(): void
    {
        $hq = $this->customer('2026-11-09');

        $this->clock();

        $this->assertNull($this->open($hq));
        $this->assertSame([], $this->stagesSent());
    }

    public function test_the_ladder_sends_each_stage_once_in_order(): void
    {
        $hq = $this->customer('2026-11-08');

        foreach (['2026-11-01', '2026-11-03', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-12'] as $day) {
            $this->on($day);
            $this->clock();
        }

        $this->on('2026-11-16');                    // grace (7 days) is over
        Artisan::call('subscriptions:reconcile');   // the lapse is recorded first (02:15)
        $this->assertSame('past_due', $this->sub($hq)->status);
        $this->clock();
        $this->on('2026-11-17');
        $this->clock();

        $this->assertSame(['due_7', 'due_1', 'due_0', 'grace', 'locked'], $this->stagesSent());
        $this->assertNotNull($this->open($hq), 'lapsed is not churned: the bill still says what restores them');
    }

    public function test_a_missed_week_catches_up_with_today_only(): void
    {
        $hq = $this->customer('2026-11-08');

        $this->on('2026-11-07');   // the first run since T−7 is the day before
        $this->clock();

        $this->assertNotNull($this->open($hq));
        $this->assertSame(['due_1'], $this->stagesSent(), 'never a backlog of stale reminders');
    }

    public function test_first_seen_mid_week_still_gets_the_due_soon_reminder(): void
    {
        $hq = $this->customer('2026-11-08');

        $this->on('2026-11-03');   // five days before: the T−7 run never happened
        $this->clock();

        $this->assertNotNull($this->open($hq));
        $this->assertSame(['due_7'], $this->stagesSent());
        Mail::assertQueued(RenewalReminderMail::class, fn ($m) => $m->daysLeft === 5 && str_contains(strip_tags($m->render()), 'in 5 days'));
    }

    public function test_an_email_that_could_not_be_queued_is_not_logged_as_sent(): void
    {
        $hq = $this->customer('2026-11-08');
        Mail::swap(new class {
            public function to($who)
            {
                return new class {
                    public function queue($mail)
                    {
                        throw new \RuntimeException('queue down (simulated)');
                    }
                };
            }
        });

        $code = Artisan::call('billing:daily');

        $this->assertSame(1, $code);
        $this->assertSame(0, BillingReminder::count(), 'not recorded — so tomorrow\'s run tries again');
        $this->assertNotNull($this->open($hq), 'the bill itself was raised');
    }

    public function test_a_renewal_a_person_voided_is_not_raised_again_that_cycle(): void
    {
        // A-16 — automation never overrules a person.
        $hq = $this->customer('2026-11-08');
        $this->clock();                                       // bill + due_7
        $operator = User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]);
        $this->actingAs($operator);
        app(BillingEngine::class)->void($this->open($hq), 'paying by cheque on the day');
        auth()->logout();

        foreach (['2026-11-02', '2026-11-07'] as $day) {
            $this->on($day);
            $out = $this->clock();
        }

        $this->assertNull($this->open($hq), 'not raised again');
        $this->assertSame(['due_7'], $this->stagesSent(), 'and not chased');
        $this->assertStringContainsString('You voided this renewal', $out);

        // Their next cycle is billed as normal.
        $this->actingAs($operator);
        app(Settlement::class)->payTerm($this->sub($hq), 'renew', ['method' => 'cheque', 'reference' => 'CHQ-1']);   // → 8 Dec
        auth()->logout();
        $this->on('2026-12-01');
        $this->clock();
        $this->assertNotNull($this->open($hq));
    }

    public function test_the_clock_runs_at_eight_in_the_morning_billing_time_whatever_the_server_zone(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => $e->description === 'billing-daily');

        $this->assertSame(['0 8 * * *', 'Asia/Kolkata'], [$event->expression, (string) $event->timezone]);
    }

    public function test_paying_early_stops_the_ladder_for_that_cycle(): void
    {
        $hq = $this->customer('2026-11-08');
        $this->clock();                                       // bill + due_7
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        app(Settlement::class)->settle($this->open($hq), ['method' => 'upi', 'amount' => 499, 'reference' => 'UPI-1']);
        auth()->logout();

        $this->on('2026-11-07');
        $this->clock();

        $this->assertSame(['due_7'], $this->stagesSent());
        $this->assertSame('2026-12-08', $this->sub($hq)->current_period_end->toDateString());
        $this->assertNull($this->open($hq));
    }

    public function test_with_emails_off_bills_are_raised_but_nothing_is_sent_or_logged(): void
    {
        PlatformSetting::set(PlatformSetting::RENEWAL_REMINDERS, false);
        $hq = $this->customer('2026-11-08');

        $this->assertStringContainsString('emails off', $this->clock());

        $this->assertNotNull($this->open($hq));
        $this->assertSame([], $this->stagesSent());
        $this->assertSame(0, BillingReminder::count());

        // Switched on later: only the stage due that day goes out.
        PlatformSetting::set(PlatformSetting::RENEWAL_REMINDERS, true);
        $this->on('2026-11-07');
        $this->clock();
        $this->assertSame(['due_1'], $this->stagesSent());
    }

    public function test_cycles_a_person_decided_about_or_that_cannot_renew_are_not_billed_or_chased(): void
    {
        $comp = $this->customer('2026-10-08', 'Comped', 'comp@clock.test');   // + 1 free month = 8 Nov
        $susp = $this->customer('2026-11-08', 'Suspended', 'susp@clock.test');
        $leaving = $this->customer('2026-11-08', 'Leaving', 'leave@clock.test');
        $trial = $this->customer('2026-11-08', 'Trial', 'trial@clock.test');
        $unbilled = $this->customer('2026-11-08', 'Unbilled', 'unbilled@clock.test');

        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        $this->on('2026-10-08');
        app(SubscriptionLifecycle::class)->commit($this->sub($comp), 'comp', ['months' => 1, 'reason' => 'launch offer']);
        $this->assertSame('2026-11-08', $this->sub($comp)->current_period_end->toDateString(), 'free until 8 Nov');
        app(SubscriptionLifecycle::class)->commit($this->sub($susp), 'suspend', ['reason' => 'dispute']);
        app(SubscriptionLifecycle::class)->ownerCancelAtPeriodEnd($this->sub($leaving));   // no override: the cancel itself is the reason
        SubscriptionLifecycle::writing(fn () => $this->sub($trial)->forceFill(['status' => 'trialing'])->save());
        $trialBill = app(BillingEngine::class)->raiseTerm($this->sub($trial), ['via' => 'owner']);   // opened Pay during the trial
        Tenant::whereKey($unbilled->id)->firstOrFail()->update(['is_billable' => false]);
        auth()->logout();
        $this->on('2026-11-01');

        $out = $this->clock();

        foreach ([$comp, $susp, $leaving, $unbilled] as $hq) {
            $this->assertNull($this->open($hq), $hq->store_name . ' must not be billed ahead');
        }
        $this->assertSame($trialBill->id, $this->open($trial)->id, 'the bill a trial opened via Pay stays, but is not chased');
        $this->assertSame([], $this->stagesSent());
        $this->assertStringContainsString('Decided by you: Complimentary', $out);
        $this->assertStringContainsString('On trial', $out);
        $this->assertStringContainsString('No store being billed', $out);
    }

    public function test_a_paid_renewals_own_override_does_not_silence_the_next_reminder(): void
    {
        // Since E3 every payment leaves `manual_renewal` until the period ends.
        $hq = $this->customer('2026-10-20');
        $this->on('2026-10-10');
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        app(Settlement::class)->payTerm($this->sub($hq), 'renew', ['method' => 'cash']);   // → 20 Nov, override until 20 Nov
        auth()->logout();
        $this->assertSame('manual_renewal', $this->sub($hq)->override_kind);

        $this->on('2026-11-13');
        $this->clock();

        $this->assertNotNull($this->open($hq));
        $this->assertSame(['due_7'], $this->stagesSent());
    }

    public function test_a_supervised_customer_is_told_how_to_pay_not_given_a_pay_button(): void
    {
        $hq = $this->customer('2026-11-08');
        $hq->account->update(['supervised' => true]);

        $this->clock();

        Mail::assertQueued(RenewalReminderMail::class, function ($m) {
            $html = $m->render();

            return $m->billedByHand && str_contains($html, 'billed directly by our team') && ! str_contains($html, 'Pay ₹');
        });
    }

    public function test_no_email_on_file_is_said_not_silently_skipped(): void
    {
        $hq = $this->customer('2026-11-08');
        $hq->account->update(['owner_user_id' => null, 'billing_email' => null]);

        $this->assertStringContainsString('no email on file', $this->clock());
        $this->assertSame(0, BillingReminder::count());
    }

    public function test_the_billing_email_is_copied_and_each_customer_gets_only_their_own(): void
    {
        $a = $this->customer('2026-11-08', 'Alpha', 'alpha@clock.test');
        $a->account->update(['billing_email' => 'accounts@alpha.test']);
        $this->customer('2026-11-08', 'Beta', 'beta@clock.test');

        $this->clock();

        Mail::assertQueued(RenewalReminderMail::class, fn ($m) => $m->hasTo('alpha@clock.test') && $m->hasTo('accounts@alpha.test') && ! $m->hasTo('beta@clock.test'));
        Mail::assertQueued(RenewalReminderMail::class, fn ($m) => $m->hasTo('beta@clock.test') && ! $m->hasTo('alpha@clock.test'));
    }

    public function test_a_dry_run_writes_and_sends_nothing(): void
    {
        $this->customer('2026-11-08');
        $count = fn () => [BillingCharge::count(), BillingReminder::count(), AdminAuditLog::count(), DB::table('jobs')->count()];
        $before = $count();

        $out = $this->clock(dry: true);

        $this->assertSame($before, $count());
        $this->assertSame([], $this->stagesSent());
        $this->assertStringContainsString('would raise ₹499.00', $out);
        $this->assertStringContainsString('would email owner@clock.test', $out);
        $this->assertStringContainsString('DRY RUN', $out);
    }

    public function test_a_stage_already_logged_by_another_run_is_not_sent_again(): void
    {
        $hq = $this->customer('2026-11-08');
        BillingReminder::create(['account_id' => $hq->account_id, 'cycle_end' => '2026-11-08', 'stage' => 'due_7', 'sent_at' => now()]);

        $this->assertStringContainsString('already sent', $this->clock());
        $this->assertSame([], $this->stagesSent());
    }

    public function test_one_customers_failure_never_stops_the_others_and_is_reported(): void
    {
        $this->customer('2026-11-08', 'Broken', 'broken@clock.test');
        $ok = $this->customer('2026-11-08', 'Fine', 'fine@clock.test');
        $this->app->instance(BillingEngine::class, new class(app(PriceResolver::class)) extends BillingEngine {
            public function raiseTerm(Subscription $subscription, array $options = []): BillingCharge
            {
                if ($subscription->account?->owner?->email === 'broken@clock.test') {
                    throw new \RuntimeException('simulated');
                }

                return parent::raiseTerm($subscription, $options);
            }
        });

        $code = Artisan::call('billing:daily');

        $this->assertSame(1, $code, 'the scheduler sees the failure');
        $this->assertStringContainsString('Error: simulated', Artisan::output());
        $this->assertNotNull($this->open($ok));
        Mail::assertQueued(RenewalReminderMail::class, fn ($m) => $m->hasTo('fine@clock.test'));
    }

    public function test_each_reminder_reads_right(): void
    {
        $mail = fn (string $stage, int $days) => new RenewalReminderMail($stage, 'Asha', 998, '2026-11-08', $days, '2026-11-15', 'CHG-2026-0042', false);

        $this->assertSame('Your OSMS renewal of ₹998.00 is due on 8 Nov', $mail('due_7', 7)->envelope()->subject);
        $this->assertStringContainsString('in 7 days', $mail('due_7', 7)->render());
        $this->assertSame('Your OSMS renewal is due tomorrow — ₹998.00', $mail('due_1', 1)->envelope()->subject);
        $this->assertSame('Your OSMS plan renews today — ₹998.00', $mail('due_0', 0)->envelope()->subject);
        $grace = strip_tags($mail('grace', -2)->render());
        $this->assertStringContainsString('keeps working until 15 Nov 2026', $grace);
        $this->assertStringContainsString('ended on 08 Nov 2026', $grace);
        $this->assertSame('Your OSMS store is paused — pay ₹998.00 to restore it', $mail('locked', -9)->envelope()->subject);
        $this->assertStringContainsString('CHG-2026-0042', $mail('locked', -9)->render());
        $this->assertStringContainsString('Pay ₹ 998.00', $mail('due_0', 0)->render());
    }

    public function test_a_month_end_cycle_is_billed_to_its_anchored_end(): void
    {
        $hq = $this->customer('2027-01-31');
        $this->on('2027-01-24');

        $this->clock();

        $this->assertSame(['2027-01-31', '2027-02-28'], [$this->open($hq)->period_start->toDateString(), $this->open($hq)->period_end->toDateString()]);
    }
}
