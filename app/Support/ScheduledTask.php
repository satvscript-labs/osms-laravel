<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * E0 / FB-01 - run a scheduled artisan command IN-PROCESS, and remember how it went.
 *
 * Why this exists
 * ---------------
 * `Schedule::command()` launches every task as a child process through Symfony
 * Process, which needs PHP's `proc_open`. Hostinger disables `proc_open` (found
 * on the same hosting account by HostelEase, 2026-10-05). When it is disabled:
 *
 *   - the child process is never started - the exception is reported to
 *     `storage/logs/laravel.log` and swallowed;
 *   - `schedule:run` prints "DONE" and exits 0 anyway, so a cron log that is
 *     being watched looks perfectly healthy;
 *   - nothing scheduled ever runs: queued mail, trial reconciliation, the
 *     archived-record purge, WhatsApp dispatch and both monitors.
 *
 * `Schedule::call()` runs a closure inside the scheduler's own process, so it
 * needs no `proc_open`. This helper is that closure.
 *
 * What it adds
 * ------------
 * A success stamp and a failure record per task, in the cache (the database
 * store in production), so "has this ever run?" has an answer that does not
 * depend on reading logs. `osms:scheduler-status` prints them. A task that
 * fails also throws, so `schedule:run` reports FAIL instead of DONE.
 *
 * Deliberately NOT here: any change to what a task does or when it runs.
 */
final class ScheduledTask
{
    /**
     * Build the closure for `Schedule::call()`.
     *
     * @param string $name    stable key; also the event name (mutex + `schedule:list`)
     * @param string $command artisan command, e.g. 'subscriptions:reconcile'
     * @param array  $params  artisan parameters, e.g. ['--max-time' => 30]
     */
    public static function command(string $name, string $command, array $params = []): Closure
    {
        return static function () use ($name, $command, $params): void {
            try {
                $exit = Artisan::call($command, $params);
            } catch (Throwable $e) {
                self::fail($name, $e->getMessage());

                throw $e;
            }

            if ($exit !== 0) {
                $tail = trim(mb_substr(trim(Artisan::output()), -300));
                self::fail($name, "exited with status {$exit}" . ($tail !== '' ? ": {$tail}" : ''));

                throw new RuntimeException("Scheduled task [{$name}] ({$command}) exited with status {$exit}.");
            }

            Cache::forever(self::key($name, 'ok'), now()->toIso8601String());
        };
    }

    /** When this task last finished successfully, or null if it never has (here). */
    public static function lastSuccess(string $name): ?Carbon
    {
        $stamp = Cache::get(self::key($name, 'ok'));

        return $stamp ? Carbon::parse($stamp) : null;
    }

    /** @return array{at: Carbon, message: string}|null the most recent failure, if any */
    public static function lastFailure(string $name): ?array
    {
        $record = Cache::get(self::key($name, 'fail'));

        if (! is_array($record) || empty($record['at'])) {
            return null;
        }

        return ['at' => Carbon::parse($record['at']), 'message' => (string) ($record['message'] ?? '')];
    }

    private static function fail(string $name, string $message): void
    {
        Cache::forever(self::key($name, 'fail'), [
            'at' => now()->toIso8601String(),
            'message' => mb_substr($message, 0, 500),
        ]);
    }

    private static function key(string $name, string $kind): string
    {
        return "scheduler:{$name}:{$kind}";
    }
}
