<?php

namespace App\Console\Commands;

use App\Mail\BackupAlertMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * OPS-01 — backup health watchdog.
 *
 * The nightly dump is a cron-driven shell script (PHP's exec() is disabled on the
 * host), so the app has no direct visibility of whether it ran. Rather than rely on
 * cron's MAILTO — which only reports when the script actually RUNS and prints
 * something, and stays silent if the cron is deleted or never fires — this checks
 * the observable outcome: does a recent, plausibly-sized backup file exist?
 *
 * That single check catches every failure mode: script error, cron removed, disk
 * full, wrong path, or a truncated dump.
 */
class MonitorBackups extends Command
{
    protected $signature = 'osms:monitor-backups';

    protected $description = 'Alert superadmins when the database backup is missing or stale (OPS-01).';

    public function handle(): int
    {
        $dir = $this->backupDir();
        $maxAgeHours = max(1, (int) config('saas.backup_max_age_hours', 26));
        $minBytes = max(0, (int) config('saas.backup_min_bytes', 1024));

        if ($dir === '' || ! is_dir($dir)) {
            return $this->reportProblem('backup directory not found', [
                'Expected directory' => $dir !== '' ? $dir : '(could not resolve $HOME/backups)',
            ]);
        }

        $files = glob(rtrim($dir, '/') . '/osms_*.sql.gz') ?: [];
        if ($files === []) {
            return $this->reportProblem('no backups found', [
                'Directory' => $dir,
                'Expected' => 'osms_*.sql.gz',
            ]);
        }

        // Newest by modification time.
        $newest = null;
        $newestMtime = -1;
        foreach ($files as $file) {
            $mtime = @filemtime($file) ?: 0;
            if ($mtime > $newestMtime) {
                $newestMtime = $mtime;
                $newest = $file;
            }
        }

        $ageHours = (time() - $newestMtime) / 3600;
        $size = (int) (@filesize($newest) ?: 0);

        $details = [
            'Newest backup' => basename((string) $newest),
            'Age' => sprintf('%.1f hours', $ageHours),
            'Size' => number_format($size) . ' bytes',
            'Backups retained' => (string) count($files),
            'Directory' => $dir,
        ];

        if ($ageHours > $maxAgeHours) {
            return $this->reportProblem(
                sprintf('backup is stale (%.1fh old, limit %dh)', $ageHours, $maxAgeHours),
                $details
            );
        }

        if ($size < $minBytes) {
            return $this->reportProblem(
                sprintf('backup is suspiciously small (%s bytes)', number_format($size)),
                $details
            );
        }

        $this->info(sprintf(
            'Backup healthy — %s, %.1fh old, %s bytes (%d retained).',
            basename((string) $newest), $ageHours, number_format($size), count($files)
        ));

        return self::SUCCESS;
    }

    /**
     * Configured directory, else $HOME/backups (matching scripts/backup-db.sh).
     */
    private function backupDir(): string
    {
        $configured = trim((string) config('saas.backup_dir', ''));
        if ($configured !== '') {
            return $configured;
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';

        return $home !== '' ? rtrim($home, '/\\') . '/backups' : '';
    }

    /**
     * Log a warning (always visible to log monitoring / an error tracker) and
     * best-effort email the superadmin(s). Returns SUCCESS so a mail outage or a
     * scheduler wrapper never turns an alert into a cascading cron failure.
     *
     * @param  array<string,string>  $details
     */
    private function reportProblem(string $problem, array $details): int
    {
        Log::warning("OPS: database backup problem — {$problem}.", $details);

        $emails = User::where('role', 'superadmin')->pluck('email')->filter()->all();
        if ($emails !== []) {
            try {
                Mail::to($emails)->send(new BackupAlertMail($problem, $details));
            } catch (\Throwable $e) {
                Log::error('OPS: could not send backup alert email: ' . $e->getMessage());
            }
        }

        $this->warn("Backup problem — {$problem} (logged and alerted).");

        return self::SUCCESS;
    }
}
