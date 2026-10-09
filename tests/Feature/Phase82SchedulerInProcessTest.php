<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\ScheduledTask;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tests\TestCase;

/**
 * E0 / FB-01 - the scheduler must work on a host that disables `proc_open`.
 *
 * Hostinger does. `Schedule::command()` launches a child process through Symfony
 * Process, which needs `proc_open`; where it is missing the task never starts, the
 * error goes only to the log, and `schedule:run` still prints DONE. Nothing
 * scheduled - queued mail, trial reconciliation, the archive purge, WhatsApp
 * dispatch, both monitors - would ever run.
 *
 * These tests are the regression guard. They are also run with `proc_open`
 * disabled (see the commit message / feat-billing E0 notes):
 *
 *     php -d disable_functions=proc_open artisan test --filter=Phase82
 *
 * Customer data is the other thing under test (D-9 / S-8): this change must alter
 * HOW tasks are launched and nothing about WHAT they do or WHEN.
 */
class Phase82SchedulerInProcessTest extends TestCase
{
    use RefreshDatabase;

    /** name => [cron expression, must not overlap] - exactly what shipped before E0. */
    private const SCHEDULE = [
        'purge-trashed' => ['0 2 * * *', false],
        'reconcile-subscriptions' => ['15 2 * * *', false],
        'whatsapp-dispatch-due' => ['* * * * *', true],
        'queue-drain' => ['* * * * *', true],
        'monitor-failed-jobs' => ['0 * * * *', true],
        'monitor-backups' => ['0 9 * * *', true],
    ];

    // ------------------------------------------------------------------
    // The invariant that stops this coming back
    // ------------------------------------------------------------------

    public function test_every_scheduled_task_runs_in_process_never_as_a_child_process(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertInstanceOf(
                CallbackEvent::class,
                $event,
                'A Schedule::command() needs proc_open, which Hostinger disables - it would silently never run. '
                . 'Use Schedule::call(ScheduledTask::command(...)) in routes/console.php. Offending task: '
                . $event->getSummaryForDisplay()
            );
        }
    }

    public function test_the_schedule_is_unchanged_in_what_runs_and_when(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => $e->description);

        // Nothing dropped, nothing added.
        $this->assertEqualsCanonicalizing(array_keys(self::SCHEDULE), $events->keys()->all());

        foreach (self::SCHEDULE as $name => [$expression, $noOverlap]) {
            $this->assertSame($expression, $events[$name]->expression, "{$name}: when it runs changed");
            $this->assertSame($noOverlap, (bool) $events[$name]->withoutOverlapping, "{$name}: overlap protection changed");
        }
    }

    // ------------------------------------------------------------------
    // ScheduledTask: success and failure are both visible
    // ------------------------------------------------------------------

    public function test_a_successful_run_is_stamped(): void
    {
        $this->assertNull(ScheduledTask::lastSuccess('probe'));

        (ScheduledTask::command('probe', 'inspire'))();

        $this->assertNotNull(ScheduledTask::lastSuccess('probe'));
        $this->assertNull(ScheduledTask::lastFailure('probe'));
    }

    public function test_a_command_that_does_not_exist_is_recorded_and_reported_not_swallowed(): void
    {
        try {
            (ScheduledTask::command('ghost', 'no:such-command'))();
            $this->fail('A task that cannot run must throw so schedule:run reports FAIL, not DONE.');
        } catch (CommandNotFoundException) {
            // expected
        }

        $this->assertNull(ScheduledTask::lastSuccess('ghost'));
        $this->assertNotNull(ScheduledTask::lastFailure('ghost'));
    }

    public function test_a_nonzero_exit_is_a_failure_and_never_stamps_success(): void
    {
        Artisan::command('probe:fails', fn () => 3);

        try {
            (ScheduledTask::command('flaky', 'probe:fails'))();
            $this->fail('A non-zero exit must throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exited with status 3', $e->getMessage());
        }

        $this->assertNull(ScheduledTask::lastSuccess('flaky'));
        $this->assertStringContainsString('status 3', ScheduledTask::lastFailure('flaky')['message']);
    }

    public function test_a_later_success_after_a_failure_reads_as_healthy_again(): void
    {
        Artisan::command('probe:flip', fn () => (int) Cache::get('probe.flip.fail', 0));

        Cache::put('probe.flip.fail', 1);
        try {
            (ScheduledTask::command('flip', 'probe:flip'))();
        } catch (RuntimeException) {
        }

        $this->assertNull(ScheduledTask::lastSuccess('flip'));

        $this->travel(5)->minutes();
        Cache::put('probe.flip.fail', 0);
        (ScheduledTask::command('flip', 'probe:flip'))();

        $this->assertTrue(ScheduledTask::lastSuccess('flip')->gt(ScheduledTask::lastFailure('flip')['at']));
    }

    // ------------------------------------------------------------------
    // End to end: the real schedule, really processing a queued job
    // ------------------------------------------------------------------

    public function test_schedule_run_drains_the_queue_in_process(): void
    {
        config(['queue.default' => 'database']);

        Phase82ProbeJob::dispatch();
        $this->assertSame(1, DB::table('jobs')->count(), 'the job should be waiting');

        Artisan::call('schedule:run');

        $this->assertSame(0, DB::table('jobs')->count(), 'the drain should have taken it');
        $this->assertTrue(Cache::has('phase82.job.ran'), 'and actually run it');
        $this->assertNotNull(ScheduledTask::lastSuccess('queue-drain'));
        $this->assertNotNull(ScheduledTask::lastSuccess('whatsapp-dispatch-due'));
    }

    // ------------------------------------------------------------------
    // The purge: dry-run is real, and the real run is unchanged
    // ------------------------------------------------------------------

    private function archivedCustomer(int $daysAgo): Customer
    {
        $tenant = Tenant::create(['store_name' => 'Archive Optical']);

        $c = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Archived ' . $daysAgo,
            'phone' => '+91 90000' . random_int(10000, 99999),
        ]);

        DB::table('customers')->where('id', $c->id)->update(['deleted_at' => now()->subDays($daysAgo)]);

        return $c;
    }

    public function test_purge_dry_run_reports_the_backlog_and_deletes_nothing(): void
    {
        $old = $this->archivedCustomer(60);
        $recent = $this->archivedCustomer(5);

        Artisan::call('model:purge-trashed', ['--dry-run' => true]);
        $out = Artisan::output(); // fetch() drains the buffer - read it once

        $this->assertStringContainsString('Dry run', $out);
        $this->assertStringContainsString('1 customers', $out);
        $this->assertDatabaseHas('customers', ['id' => $old->id]);
        $this->assertDatabaseHas('customers', ['id' => $recent->id]);
    }

    public function test_the_real_purge_still_deletes_only_what_is_past_the_window(): void
    {
        $old = $this->archivedCustomer(60);
        $recent = $this->archivedCustomer(5);

        $this->artisan('model:purge-trashed')->assertSuccessful();

        $this->assertDatabaseMissing('customers', ['id' => $old->id]);
        $this->assertSoftDeleted('customers', ['id' => $recent->id]);
    }

    // ------------------------------------------------------------------
    // osms:scheduler-status: honest, and read-only
    // ------------------------------------------------------------------

    public function test_status_says_never_run_and_shows_the_preflight_before_the_first_run(): void
    {
        Artisan::call('osms:scheduler-status');
        $out = Artisan::output();

        $this->assertStringContainsString('NEVER RUN', $out);
        $this->assertStringContainsString('FIRST-RUN PRE-FLIGHT', $out);
        $this->assertStringContainsString('Dry run - nothing deleted', $out);
    }

    public function test_the_preflight_names_the_trial_the_first_reconcile_would_cancel(): void
    {
        $tenant = Tenant::create(['store_name' => 'Lapsed Optical']);
        Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update([
            'status' => 'trialing',
            'current_period_end' => now(config('billing.timezone'))->subDays(60),
        ]);

        Artisan::call('osms:scheduler-status');
        $out = Artisan::output();

        $this->assertStringContainsString('Lapsed Optical', $out);
        $this->assertStringContainsString('BEFORE the scheduler runs', $out);
    }

    public function test_a_trial_still_in_window_is_not_flagged(): void
    {
        Tenant::create(['store_name' => 'Fresh Optical']);

        Artisan::call('osms:scheduler-status');
        $out = Artisan::output();

        $this->assertStringNotContainsString('Fresh Optical', $out);
        $this->assertStringContainsString('none past their end date', $out);
    }

    public function test_status_is_read_only(): void
    {
        $tenant = Tenant::create(['store_name' => 'Lapsed Optical']);
        Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update([
            'status' => 'trialing',
            'current_period_end' => now(config('billing.timezone'))->subDays(60),
        ]);
        $old = $this->archivedCustomer(60);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->timestamp, 'created_at' => now()->subDays(3)->timestamp,
        ]);

        Artisan::call('osms:scheduler-status', ['--preflight' => true]);
        $out = Artisan::output();

        $this->assertSame('trialing', Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('status'));
        $this->assertDatabaseHas('customers', ['id' => $old->id]);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringContainsString('1 job(s) have been waiting over a day', $out);
    }

    public function test_status_goes_quiet_once_the_risky_tasks_have_run_and_nothing_is_overdue(): void
    {
        (ScheduledTask::command('purge-trashed', 'inspire'))();
        (ScheduledTask::command('reconcile-subscriptions', 'inspire'))();
        (ScheduledTask::command('whatsapp-dispatch-due', 'inspire'))();
        (ScheduledTask::command('queue-drain', 'inspire'))();
        (ScheduledTask::command('monitor-failed-jobs', 'inspire'))();
        (ScheduledTask::command('monitor-backups', 'inspire'))();

        Artisan::call('osms:scheduler-status');
        $out = Artisan::output();

        $this->assertStringNotContainsString('NEVER RUN', $out);
        $this->assertStringNotContainsString('OVERDUE', $out);
        $this->assertStringNotContainsString('FIRST-RUN PRE-FLIGHT', $out);
    }

    public function test_a_task_that_stopped_running_reads_overdue(): void
    {
        // Last ran three days ago; it is a minutely task, so it should have run since.
        $this->travel(-3)->days();
        (ScheduledTask::command('queue-drain', 'inspire'))();
        $this->travelBack();

        Artisan::call('osms:scheduler-status');
        $out = Artisan::output();

        $this->assertMatchesRegularExpression('/queue-drain[^\n]*OVERDUE/', $out);
    }
}

/** A real queued job, so the end-to-end test proves work is processed rather than merely "reported DONE". */
class Phase82ProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        Cache::put('phase82.job.ran', true);
    }
}
