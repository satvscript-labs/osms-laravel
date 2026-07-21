<?php

namespace App\Console\Commands;

use App\Mail\FailedJobsAlertMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * OPS-02 — the shared-hosting queue is cron-drained with no persistent worker and
 * no dashboard, so a failed job is otherwise invisible. This runs on the scheduler,
 * inspects the `failed_jobs` table directly (independent of the queue itself), and
 * — when anything has failed — logs a WARNING (picked up by log monitoring / an
 * error tracker) and best-effort emails the superadmin(s).
 */
class MonitorFailedJobs extends Command
{
    protected $signature = 'osms:monitor-failed-jobs';

    protected $description = 'Alert superadmins when queued jobs have failed (OPS-02).';

    public function handle(): int
    {
        $count = DB::table('failed_jobs')->count();

        // WA-02 — failed WhatsApp sends have no UI surface, so report them here too.
        $failedMessages = DB::table('whatsapp_messages')->where('status', 'failed')->count();
        if ($failedMessages > 0) {
            Log::warning("OPS: {$failedMessages} WhatsApp message(s) failed to send.", [
                'count' => $failedMessages,
            ]);
        }

        if ($count === 0) {
            $this->info($failedMessages > 0
                ? "No failed jobs. ({$failedMessages} failed WhatsApp message(s) logged.)"
                : 'No failed jobs.');

            return self::SUCCESS;
        }

        $recent = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(5)
            ->get(['queue', 'failed_at', 'exception'])
            ->map(fn ($j) => [
                'queue' => $j->queue ?? 'default',
                'failed_at' => (string) $j->failed_at,
                'summary' => Str::limit((string) Str::before($j->exception, "\n"), 140),
            ])
            ->all();

        // Always log — visible in the log channel / error tracker even if mail is down.
        Log::warning("OPS: {$count} failed background job(s) in the queue.", ['recent' => $recent]);

        // Best-effort synchronous email to superadmins (never queued — see the Mailable).
        $emails = User::where('role', 'superadmin')->pluck('email')->filter()->all();
        if ($emails !== []) {
            try {
                Mail::to($emails)->send(new FailedJobsAlertMail($count, $recent));
            } catch (\Throwable $e) {
                Log::error('OPS: could not send failed-jobs alert email: ' . $e->getMessage());
            }
        }

        $this->warn("{$count} failed job(s) — logged and alerted.");

        return self::SUCCESS;
    }
}
