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
Schedule::command('whatsapp:dispatch-due')->everyMinute()->withoutOverlapping();

// Queued mail (staff invitations, trial-status reminders) has no persistent worker on
// shared hosting — drain the jobs table every minute via the scheduler cron instead.
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();

// OPS-02 — surface failed background jobs (the cron-only queue has no dashboard).
// Alerts the superadmin(s) so a silently-broken queue doesn't go unnoticed.
Schedule::command('osms:monitor-failed-jobs')->hourly()->withoutOverlapping();
