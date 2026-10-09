<?php

use App\Support\ScheduledTask;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// E0 / FB-01 — EVERY task below is Schedule::call(ScheduledTask::command(...)), never
// Schedule::command(...). The latter launches a child process through Symfony Process,
// which needs PHP's proc_open — and Hostinger disables it. Where it is disabled the task
// never starts, the error goes only to storage/logs/laravel.log, and `schedule:run` still
// prints DONE and exits 0, so a healthy-looking cron log can hide a scheduler that has
// never run anything. A closure runs inside the scheduler's own process and needs no
// proc_open. SchedulerInProcessTest fails if a Schedule::command() is added back;
// `php artisan osms:scheduler-status` shows what has actually run.
//
// Times and overlap protection are unchanged. A callback event needs name() before
// withoutOverlapping(); the name doubles as the mutex key.

// FG-Delete — nightly purge of archived records past their 30-day window.
Schedule::call(ScheduledTask::command('purge-trashed', 'model:purge-trashed'))
    ->name('purge-trashed')->dailyAt('02:00');

// ST-Enforce (S1) — reconcile expired trials so subscription state stays accurate.
Schedule::call(ScheduledTask::command('reconcile-subscriptions', 'subscriptions:reconcile'))
    ->name('reconcile-subscriptions')->dailyAt('02:15');

// FT-WhatsApp — sweep due scheduled messages onto the queue every minute (the
// 60s undo window resolves on the next tick; no persistent worker needed).
//
// NOTE on withoutOverlapping(5): the default lock lasts 1440 minutes (24h). Hostinger
// wraps cron commands in `timeout`, so a long-running task can be KILLED before it
// releases its lock — which then silently blocks the task for a whole day and makes
// `schedule:run` report "No scheduled commands are ready to run". A 5-minute expiry
// means a killed task self-heals on the next tick instead.
Schedule::call(ScheduledTask::command('whatsapp-dispatch-due', 'whatsapp:dispatch-due'))
    ->name('whatsapp-dispatch-due')->everyMinute()->withoutOverlapping(5);

// Queued mail (staff invitations, trial-status reminders) has no persistent worker on
// shared hosting — drain the jobs table every minute via the scheduler cron instead.
// --max-time is kept well under a typical cron timeout so the worker exits cleanly
// and releases its lock rather than being killed mid-run (see note above).
//
// In-process is safe: Worker::stop() returns a status rather than exit()ing, so the rest
// of the schedule still runs after the drain. (Only a job that overruns its own timeout
// is killed, exactly as it was when this ran as a child process.)
Schedule::call(ScheduledTask::command('queue-drain', 'queue:work', ['--stop-when-empty' => true, '--max-time' => 30]))
    ->name('queue-drain')->everyMinute()->withoutOverlapping(5);

// OPS-02 — surface failed background jobs (the cron-only queue has no dashboard).
// Alerts the superadmin(s) so a silently-broken queue doesn't go unnoticed.
Schedule::call(ScheduledTask::command('monitor-failed-jobs', 'osms:monitor-failed-jobs'))
    ->name('monitor-failed-jobs')->hourly()->withoutOverlapping(5);

// OPS-01 — verify the nightly dump actually happened. The backup is a cron-driven
// shell script the app can't observe directly, so this checks that a recent backup
// FILE exists. Unlike cron's MAILTO, that also catches the cron being deleted or
// never firing. Runs well after the 02:00/02:30 backup window.
Schedule::call(ScheduledTask::command('monitor-backups', 'osms:monitor-backups'))
    ->name('monitor-backups')->dailyAt('09:00')->withoutOverlapping(5);
