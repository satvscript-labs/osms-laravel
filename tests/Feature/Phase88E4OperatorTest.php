<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BillingReminder;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingEngine;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use App\Support\RenewalNudge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * E4.6 — the operator's side of the clock: Owed now vs coming due (E4-H), the
 * worklist with amounts, reminders and a WhatsApp nudge (E4-I), email preview (E4-D).
 */
class Phase88E4OperatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Plan::where('code', 'basic')->update(['monthly_price' => 499, 'yearly_price' => 4999, 'price_tiers' => null]);
        $this->on('2026-11-01 10:00');
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
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

    private function customer(string $endsOn, string $owner = 'Asha Mehta', ?string $phone = null): Tenant
    {
        $hq = app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin', 'name' => $owner]), ['store_name' => $owner . ' Optical']);
        $this->sub($hq)->forceFill(['status' => 'active', 'interval' => 'monthly', 'current_period_end' => $endsOn])->save();
        $hq->account->update(['billing_phone' => $phone]);

        return $hq;
    }

    private function sub(Tenant $of): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $of->account_id)->firstOrFail();
    }

    private function today()
    {
        return $this->withSession(['auth.password_confirmed_at' => time()])->get(route('superadmin.dashboard'))->assertOk();
    }

    public function test_bills_raised_ahead_are_coming_due_not_owed_now(): void
    {
        $ahead = $this->customer('2026-11-06');
        app(BillingEngine::class)->raiseTerm($this->sub($ahead), ['via' => 'system']);

        $o = app(BillingEngine::class)->owedSummary();
        $this->assertEquals([0.0, 499.0, 499.0], [$o['due_now'], $o['upcoming'], $o['total']]);
        $this->today()->assertSee('Owed now')->assertSee('+ ₹ 499 coming due (renewals raised ahead)');

        $late = $this->customer('2026-10-29', 'Ravi Shah');
        $this->on('2026-10-20 10:00');
        app(BillingEngine::class)->raiseTerm($this->sub($late), ['via' => 'system']);   // due 29 Oct
        $this->on('2026-11-01 10:00');

        $o = app(BillingEngine::class)->owedSummary();
        $this->assertEquals([499.0, 499.0, 499.0], [$o['due_now'], $o['aged']['fresh'], $o['upcoming']]);
    }

    public function test_the_worklist_says_how_much_when_they_were_reminded_and_offers_a_whatsapp_nudge(): void
    {
        $hq = $this->customer('2026-11-06', 'Asha Mehta', '98765 43210');
        $bill = app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'system']);
        BillingReminder::create(['account_id' => $hq->account_id, 'charge_id' => $bill->id, 'cycle_end' => '2026-11-06',
            'stage' => 'due_7', 'sent_at' => now()->subDays(2)]);
        $this->customer('2026-11-08', 'Nophone Kumar');

        $html = $this->today()
            ->assertSee('₹ 499')
            ->assertSee('Due-soon reminder 2 days ago')
            ->assertSee('https://wa.me/919876543210?text=', false)
            ->assertSee('Add phone')
            ->getContent();

        $this->assertStringContainsString(rawurlencode('₹499.00 is due on 6 Nov 2026'), $html);
        $this->assertStringContainsString('not billed yet', $html, 'a renewal not raised yet is shown at today\'s price, and says so');
    }

    public function test_a_period_you_gave_is_a_decision_not_a_chase(): void
    {
        $hq = $this->customer('2026-10-08', 'Comp Customer', '98765 43210');
        $this->on('2026-10-01 10:00');
        app(SubscriptionLifecycle::class)->commit($this->sub($hq), 'comp', ['months' => 1, 'reason' => 'launch']);   // → 8 Nov
        $this->on('2026-11-01 10:00');

        $this->today()->assertSee('Free period ends — decide')->assertDontSee('wa.me', false)->assertDontSee('Add phone');
    }

    public function test_the_customer_page_says_due_for_a_bill_raised_ahead_and_owed_once_it_is_due(): void
    {
        $hq = $this->customer('2026-11-06');
        app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'system']);
        $page = fn () => $this->withSession(['auth.password_confirmed_at' => time()])->get(route('superadmin.accounts.show', $hq->account_id))->assertOk();

        $page()->assertSee('₹ 499.00 due 06 Nov')->assertDontSee('₹ 499.00 owed');

        $this->on('2026-11-06 10:00');
        $page()->assertSee('₹ 499.00 owed')->assertDontSee('₹ 499.00 due 06 Nov');
    }

    public function test_a_bill_due_today_is_owed_now_whatever_the_hour(): void
    {
        $hq = $this->customer('2026-11-01');
        $this->on('2026-10-25 10:00');
        app(BillingEngine::class)->raiseTerm($this->sub($hq), ['via' => 'system']);   // due 1 Nov

        $this->on('2026-11-01 01:00');   // just after midnight in India: still 31 Oct in UTC
        $o = app(BillingEngine::class)->owedSummary();

        $this->assertEquals([499.0, 0.0], [$o['due_now'], $o['upcoming']], 'due today is due, not coming due');
    }

    public function test_days_left_are_counted_in_the_billing_timezone(): void
    {
        $this->customer('2026-11-02');
        $this->on('2026-11-01 01:00');   // 31 Oct in UTC

        $this->today()->assertSee('in 1 day');
    }

    public function test_the_nudge_is_written_for_the_customer(): void
    {
        \Tests\Fakes\FakeRazorpay::install($this->app);   // online payment configured
        $account = Account::create(['name' => 'Nudge', 'billing_phone' => '+44 7700 900123']);
        $due = Carbon::parse('2026-11-06');

        $url = RenewalNudge::whatsappUrl($account, 998, $due, false);
        $this->assertStringStartsWith('https://wa.me/447700900123?text=', $url, 'a foreign number keeps its own code');
        $this->assertSame('Hello Nudge, this is OSMS. Your renewal of ₹998.00 is due on 6 Nov 2026. You can pay online here: ' . route('tenant.billing.index') . ' Thank you!',
            RenewalNudge::message($account, 998, $due, false));
        $this->assertStringContainsString('was due on 6 Nov 2026. Please pay it', RenewalNudge::message($account, 998, $due, true));

        $account->update(['supervised' => true]);
        $this->assertStringContainsString('Reply here and we will share how to pay.', RenewalNudge::message($account->fresh(), 998, $due, false));

        $account->update(['supervised' => false]);
        config(['services.razorpay.key' => null]);
        $this->app->forgetInstance(\App\Services\BillingService::class);
        $this->assertStringContainsString('Reply here and we will share how to pay.', RenewalNudge::message($account->fresh(), 998, $due, false), 'no gateway, no online promise');

        $account->update(['billing_phone' => '12']);
        $this->assertNull(RenewalNudge::whatsappUrl($account->fresh(), 998, $due, false), 'no usable number, no link');
    }

    public function test_every_reminder_can_be_read_before_anyone_is_emailed(): void
    {
        foreach (['due_7' => 'Your renewal is coming up', 'due_1' => 'Your renewal is due tomorrow', 'due_0' => 'Your renewal is due today', 'grace' => 'Your payment is overdue', 'locked' => 'Your store is paused'] as $stage => $text) {
            $this->get(route('superadmin.platform.reminder-preview', ['stage' => $stage]))->assertOk()->assertSee($text);
        }
        $this->get(route('superadmin.platform.reminder-preview', ['stage' => 'due_7', 'by_hand' => 1]))->assertSee('billed directly by our team');

        // By default the preview is what customers would get today: no gateway here, so no Pay button.
        $this->get(route('superadmin.platform.reminder-preview'))->assertSee('billed directly by our team')->assertDontSee('Pay ₹');
        \Tests\Fakes\FakeRazorpay::install($this->app);
        $this->get(route('superadmin.platform.reminder-preview'))->assertSee('Pay ₹ 998.00')->assertDontSee('billed directly by our team');

        $this->actingAs(User::factory()->create(['role' => 'store_admin']))
            ->get(route('superadmin.platform.reminder-preview'))->assertForbidden();
    }
}
