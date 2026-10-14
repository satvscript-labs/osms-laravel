<?php

namespace App\Console\Commands;

use App\Services\RenewalClock;
use Illuminate\Console\Command;

/**
 * E4 — the daily billing clock: raise renewal bills 7 days ahead, keep open ones
 * honest, and send the renewal reminder due today (16_E4_PLAN.md, E4-J).
 *
 *   php artisan billing:daily --dry-run   — what it would do; writes and sends nothing
 */
class BillingDaily extends Command
{
    protected $signature = 'billing:daily {--dry-run : Show what would happen; raise, send and log nothing}';

    protected $description = 'Raise renewal bills ahead, re-quote open ones, and send due renewal reminders.';

    public function handle(RenewalClock $clock): int
    {
        $dry = (bool) $this->option('dry-run');
        $lines = $clock->run($dry);

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
