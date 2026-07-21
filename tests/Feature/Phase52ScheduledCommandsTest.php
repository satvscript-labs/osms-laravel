<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Mail\TrialStatusMail;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TEST-03 — the scheduled/ops layer had no coverage of its decision paths. These
 * pin the behaviour the cron depends on: trial reconciliation + reminder emails,
 * and the WhatsApp due-sweep only dispatching genuinely due, still-scheduled rows.
 */
class Phase52ScheduledCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithTrial(int $daysLeft, string $status = 'trialing'): Tenant
    {
        $tenant = Tenant::create(['store_name' => 'Sched ' . uniqid(), 'address' => 'X']);
        $tenant->subscription->update([
            'status' => $status,
            'current_period_end' => now()->addDays($daysLeft),
        ]);
        User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'store_admin']);

        return $tenant;
    }

    // ---- subscriptions:reconcile ----

    public function test_reconcile_flips_an_expired_trial_to_canceled_and_emails_the_admin(): void
    {
        Mail::fake();
        $tenant = $this->tenantWithTrial(-3); // ended 3 days ago

        $this->artisan('subscriptions:reconcile')->assertExitCode(0);

        $this->assertSame('canceled', $tenant->subscription->fresh()->status);
        Mail::assertQueued(TrialStatusMail::class);
    }

    public function test_reconcile_leaves_an_in_window_trial_alone(): void
    {
        Mail::fake();
        $tenant = $this->tenantWithTrial(10);

        $this->artisan('subscriptions:reconcile')->assertExitCode(0);

        $this->assertSame('trialing', $tenant->subscription->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_reconcile_sends_a_reminder_at_the_three_day_threshold(): void
    {
        Mail::fake();
        $this->tenantWithTrial(3);

        $this->artisan('subscriptions:reconcile')->assertExitCode(0);

        Mail::assertQueued(TrialStatusMail::class);
    }

    // ---- whatsapp:dispatch-due ----

    public function test_dispatch_due_only_queues_due_scheduled_messages(): void
    {
        Queue::fake();
        $tenant = Tenant::create(['store_name' => 'WA Sched', 'address' => 'X']);

        $due = WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'event' => 'order_ready', 'to_phone' => '+919000000001',
            'status' => 'scheduled', 'scheduled_for' => now()->subMinute(),
        ]);
        // Not due yet (still inside the undo window).
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'event' => 'order_ready', 'to_phone' => '+919000000002',
            'status' => 'scheduled', 'scheduled_for' => now()->addMinutes(5),
        ]);
        // Already cancelled (reverted) — must never be dispatched.
        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'event' => 'order_ready', 'to_phone' => '+919000000003',
            'status' => 'cancelled', 'scheduled_for' => now()->subMinute(),
        ]);

        $this->artisan('whatsapp:dispatch-due')->assertExitCode(0);

        Queue::assertPushed(SendWhatsAppMessage::class, 1);
        Queue::assertPushed(fn (SendWhatsAppMessage $job) => $job->messageId === $due->id);
    }

    public function test_dispatch_due_is_a_noop_when_nothing_is_due(): void
    {
        Queue::fake();

        $this->artisan('whatsapp:dispatch-due')->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
