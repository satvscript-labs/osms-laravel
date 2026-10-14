<?php

namespace App\Console\Commands;

use App\Services\RenewalClock;
use Illuminate\Console\Command;

/**
 * E4 — the daily billing clock: raise renewal bills 7 days ahead, keep open ones
 * honest, and send the renewal reminder due today (16_E4_PLAN.md, E4-J).
 *
 *   php artisan billing:daily --dry-run                     — what it would do today; writes and sends nothing
 *   php artisan billing:daily --dry-run --as-of=2026-10-25  — what it would do that morning
 */
class BillingDaily extends Command
{
    protected $signature = 'billing:daily
        {--dry-run : Show what would happen; raise, send and log nothing}
        {--as-of= : With --dry-run only: pretend it is 08:00 on this date (YYYY-MM-DD)}';

    protected $description = 'Raise renewal bills ahead, re-quote open ones, and send due renewal reminders.';

    public function handle(RenewalClock $clock): int
    {
        $dry = (bool) $this->option('dry-run');
        $asOf = $this->option('as-of');

        if ($asOf !== null) {
            // Only ever a preview: a real run on a pretend date would bill and email for real.
            if (! $dry || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $asOf)) {
                $this->error('--as-of needs --dry-run and a date like 2026-10-25.');

                return self::INVALID;
            }

            \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse($asOf . ' 08:00:00', config('billing.timezone', 'Asia/Kolkata')));
            $this->line("As of {$asOf} 08:00 (" . config('billing.timezone', 'Asia/Kolkata') . '):');
        }

        try {
            $lines = $clock->run($dry);
        } finally {
            if ($asOf !== null) {
                \Illuminate\Support\Carbon::setTestNow();
            }
        }

        if ($lines === []) {
            $this->info('Nothing due within ' . RenewalClock::LEAD_DAYS . ' days and no open renewal bills.');

            return self::SUCCESS;
        }

        $this->table(
            ['Customer', 'Status', 'Period ends', 'Days', 'Bill', 'Reminder', 'Email', 'Note'],
            array_map(fn ($l) => [
                mb_substr($l['account'], 0, 28), $l['status'], $l['ends'] ?? '—', $l['days'] ?? '—',
                $l['bill'], $l['stage'] ?? '—', $l['email'], $l['note'] ?? '',
            ], $lines),
        );

        $errors = count(array_filter($lines, fn ($l) => $l['error']));
        $this->line(($dry ? 'DRY RUN — nothing was raised, sent or logged. ' : '')
            . count($lines) . ' customer(s) checked' . ($errors ? ", {$errors} error(s)" : '') . '.');

        // A failure is reported to the scheduler (osms:scheduler-status), never hidden.
        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
