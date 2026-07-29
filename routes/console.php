<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FG-Delete — nightly purge of archived records past their 30-day window.
Schedule::command('model:purge-trashed')->dailyAt('02:00');

// ST-Enforce (S1) — reconcile expired trials so subscription state stays accurate.
Schedule::command('subscriptions:reconcile')->dailyAt('02:15');

// FT-WhatsApp — sweep due scheduled messages onto the queue every minute (the
// 60s undo window resolves on the next tick; no persistent worker needed).
//
// NOTE on withoutOverlapping(5): the default lock lasts 1440 minutes (24h). Hostinger
// wraps cron commands in `timeout`, so a long-running task can be KILLED before it
// releases its lock — which then silently blocks the task for a whole day and makes
// `schedule:run` report "No scheduled commands are ready to run". A 5-minute expiry
// means a killed task self-heals on the next tick instead.
Schedule::command('whatsapp:dispatch-due')->everyMinute()->withoutOverlapping(5);

// Queued mail (staff invitations, trial-status reminders) has no persistent worker on
// shared hosting — drain the jobs table every minute via the scheduler cron instead.
// --max-time is kept well under a typical cron timeout so the worker exits cleanly
// and releases its lock rather than being killed mid-run (see note above).
Schedule::command('queue:work --stop-when-empty --max-time=30')->everyMinute()->withoutOverlapping(5);

// OPS-02 — surface failed background jobs (the cron-only queue has no dashboard).
// Alerts the superadmin(s) so a silently-broken queue doesn't go unnoticed.
Schedule::command('osms:monitor-failed-jobs')->hourly()->withoutOverlapping(5);

// OPS-01 — verify the nightly dump actually happened. The backup is a cron-driven
// shell script the app can't observe directly, so this checks that a recent backup
// FILE exists. Unlike cron's MAILTO, that also catches the cron being deleted or
// never firing. Runs well after the 02:00/02:30 backup window.
Schedule::command('osms:monitor-backups')->dailyAt('09:00')->withoutOverlapping(5);

// P5 — the scheduler's own heartbeat.
//
// The panel's ops surface needs to answer "is the cron alive?" honestly. On this
// host the app cannot see cron at all, and inferring health from side effects is
// how a dead scheduler stays invisible for a week. So the scheduler stamps the
// time every five minutes; a stale stamp means exactly one thing.
Schedule::call(fn () => \App\Models\PlatformSetting::set(
    \App\Models\PlatformSetting::SCHEDULER_HEARTBEAT,
    now()->toIso8601String(),
))->everyFiveMinutes()->name('scheduler-heartbeat')->withoutOverlapping(5);
