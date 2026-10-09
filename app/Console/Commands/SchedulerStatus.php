<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Support\ScheduledTask;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E0 / FB-01 - "is the scheduler actually doing anything?", answered without
 * reading logs.
 *
 * `schedule:list` only proves what SHOULD run, and `schedule:run` prints DONE
 * even when the host (Hostinger disables `proc_open`) stopped the task from
 * starting - so neither answers the question. This reads the per-task success
 * stamps `ScheduledTask` writes, and reports whether each task has run, is
 * overdue, or is failing.
 *
 * It also carries the first-run PRE-FLIGHT (D-9 / S-8). If tasks have been
 * silently doing nothing, the first working run will do months of deferred work
 * at once: cancel every expired trial, hard-delete everything archived for over
 * 30 days, and send every queued email. This prints what that would be, and
 * changes nothing.
 *
 * Read-only by construction.
 */
class SchedulerStatus extends Command
{
    protected $signature = 'osms:scheduler-status
        {--preflight : Always show the first-run pre-flight, even if tasks have run}';

    protected $description = 'Show what the scheduler has actually run, and what its first run would do';

    /** Seconds of slack before a task is called overdue (cron fires on the minute). */
    private const GRACE_SECONDS = 150;

    public function handle(Schedule $schedule): int
    {
        $this->environment();

        $this->tasks($schedule);

        $this->queue();

        // The pre-flight matters exactly when the two destructive-ish tasks have
        // never succeeded here - i.e. their next run is their first.
        $firstRun = ScheduledTask::lastSuccess('purge-trashed') === null
            || ScheduledTask::lastSuccess('reconcile-subscriptions') === null;

        if ($firstRun || $this->option('preflight')) {
            $this->preflight();
        }

        return self::SUCCESS;
    }

    private function environment(): void
    {
        $procOpen = function_exists('proc_open');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Environment</>', '');
        $this->components->twoColumnDetail(
            'proc_open',
            $procOpen
                ? '<fg=green>available</>'
                : '<fg=yellow>DISABLED</> - child processes cannot start here; every task is in-process, so this is fine',
        );
        $this->components->twoColumnDetail('Cache store (holds the stamps)', (string) config('cache.default'));
        $this->components->twoColumnDetail('Queue connection', (string) config('queue.default'));
    }

    private function tasks(Schedule $schedule): void
    {
        $rows = [];
        $now = now();

        foreach ($schedule->events() as $event) {
            /** @var Event $event */
            $name = $event->description ?: $event->getSummaryForDisplay();
            $ok = ScheduledTask::lastSuccess($name);
            $fail = ScheduledTask::lastFailure($name);

            $rows[] = [
                $name,
                $event->expression,
                $ok ? $ok->diffForHumans() : '-',
                $this->verdict($event, $ok, $fail, $now),
            ];
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Tasks</>', '');
        $this->table(['Task', 'Cron', 'Last success', 'Status'], $rows);
    }

    private function verdict(Event $event, ?Carbon $ok, ?array $fail, Carbon $now): string
    {
        if ($fail && (! $ok || $fail['at']->gt($ok))) {
            return '<fg=red>FAILING</> (' . $fail['at']->diffForHumans() . '): ' . mb_substr($fail['message'], 0, 80);
        }

        if (! $ok) {
            return '<fg=yellow>NEVER RUN</>';
        }

        // Latest moment this task should already have run by.
        $due = Carbon::instance(
            (new CronExpression($event->expression))->getPreviousRunDate($now, 0, true)
        );

        return $ok->lt($due->copy()->subSeconds(self::GRACE_SECONDS))
            ? '<fg=red>OVERDUE</>'
            : '<fg=green>ok</>';
    }

    private function queue(): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Queue</>', '');

        if (! Schema::hasTable('jobs')) {
            $this->components->twoColumnDetail('jobs table', 'not present');

            return;
        }

        $pending = DB::table('jobs')->count();
        $oldest = DB::table('jobs')->min('created_at');

        $this->components->twoColumnDetail('Waiting to send', (string) $pending);

        if ($pending > 0 && $oldest) {
            $this->components->twoColumnDetail(
                'Oldest waiting',
                Carbon::createFromTimestamp((int) $oldest)->diffForHumans(),
            );
        }

        if (Schema::hasTable('failed_jobs')) {
            $this->components->twoColumnDetail('Failed', (string) DB::table('failed_jobs')->count());
        }
    }

    private function preflight(): void
    {
        $this->newLine();
        $this->components->warn('FIRST-RUN PRE-FLIGHT - read this before switching the scheduler on. Nothing below has been done.');

        // 1) Archived records the purge would hard-delete.
        $this->call('model:purge-trashed', ['--dry-run' => true]);

        // 2) Trials the reconcile would cancel (and email about), by name.
        $tz = config('billing.timezone', 'Asia/Kolkata');

        $lapsed = Subscription::withoutGlobalScopes()
            ->where('status', 'trialing')
            ->whereNotNull('current_period_end')
            ->with('tenant')
            ->get()
            // Mirror subscriptions:reconcile - and honour an operator override where
            // that concept exists, so this never over-warns about a protected trial.
            ->filter(fn (Subscription $s) => $s->accessState() === 'locked'
                && ! (method_exists($s, 'hasActiveOverride') && $s->hasActiveOverride()));

        if ($lapsed->isEmpty()) {
            $this->line('Trials: none past their end date - subscriptions:reconcile would cancel nobody.');
        } else {
            $this->line('<fg=yellow>subscriptions:reconcile would CANCEL these ' . $lapsed->count()
                . ' trial(s) and email their admins "your trial has ended":</>');

            foreach ($lapsed->take(25) as $s) {
                $this->line('  - ' . ($s->tenant?->store_name ?? $s->tenant_id)
                    . ' (trial ended ' . $s->current_period_end->setTimezone($tz)->toFormattedDateString() . ')');
            }

            $this->line('  Extend or comp any customer you want to keep BEFORE the scheduler runs.');
        }

        // 3) Mail that will go out the moment the queue drains.
        if (Schema::hasTable('jobs')) {
            $stale = DB::table('jobs')->where('created_at', '<', now()->subDay()->timestamp)->count();

            $this->line($stale > 0
                ? "<fg=yellow>Queue: {$stale} job(s) have been waiting over a day and will be sent as soon as the queue drains.</>"
                : 'Queue: nothing old is waiting.');
        }
    }
}
