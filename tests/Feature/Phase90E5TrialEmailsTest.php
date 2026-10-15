<?php

namespace Tests\Feature;

use App\Mail\TrialStatusMail;
use App\Models\BillingReminder;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StoreProvisioner;
use App\Services\SubscriptionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * E5.3 (O-3, E5-C) — trial emails on the sent-log: each stage once per trial, the
 * current stage only after a missed morning, nothing extra on a doubled one.
 */
class Phase90E5TrialEmailsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $hq;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Mail::fake();
        $this->on('2026-11-01');
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        $this->hq = app(StoreProvisioner::class)->provision(User::factory()->create(['tenant_id' => null, 'role' => 'store_admin', 'email' => 'trial@owner.test']), ['store_name' => 'Trial Optical']);
        $this->sub()->forceFill(['status' => 'trialing', 'current_period_end' => '2026-11-04'])->save();   // 3 days left
        auth()->logout();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function on(string $day): void
    {
        Carbon::setTestNow(Carbon::parse($day . ' 07:45:00', config('billing.timezone')));
    }

    private function sub(): Subscription
    {
        return Subscription::withoutGlobalScopes()->where('account_id', $this->hq->account_id)->firstOrFail();
    }

    private function reconcile(): void
    {
        Artisan::call('subscriptions:reconcile');
    }

    /** @return list<int> days-left of each trial email queued, in order */
    private function sent(): array
    {
        return Mail::queued(TrialStatusMail::class)->map(fn ($m) => $m->daysLeft)->values()->all();
    }

    public function test_each_stage_goes_once_however_often_the_morning_runs(): void
    {
        foreach (['2026-11-01', '2026-11-01', '2026-11-02', '2026-11-03', '2026-11-03', '2026-11-05', '2026-11-05'] as $day) {
            $this->on($day);
            $this->reconcile();
        }

        $this->assertSame([3, 1, 0], $this->sent(), '3 days, 1 day, ended — once each');
        $this->assertSame(['trial_3', 'trial_1', 'trial_0'], BillingReminder::orderBy('sent_at')->pluck('stage')->all());
        $this->assertSame('canceled', $this->sub()->status);
    }

    public function test_a_missed_morning_catches_up_with_the_current_stage_only(): void
    {
        $this->on('2026-11-02');   // the 3-days run never happened; 2 days left
        $this->reconcile();
        $this->on('2026-11-03');
        $this->reconcile();

        $this->assertSame([2, 1], $this->sent());
    }

    public function test_a_trial_you_extended_is_not_chased(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin', 'tenant_id' => null]));
        app(SubscriptionLifecycle::class)->commit($this->sub(), 'extend', ['days' => 1, 'reason' => 'asked for more time']);
        auth()->logout();

        $this->reconcile();

        $this->assertSame([], $this->sent());
    }

    public function test_trial_emails_do_not_depend_on_the_renewal_reminders_switch(): void
    {
        PlatformSetting::set(PlatformSetting::RENEWAL_REMINDERS, false);

        $this->reconcile();

        $this->assertSame([3], $this->sent(), 'trial emails have always been on');
    }
}
