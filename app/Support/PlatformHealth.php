<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P5 — "is anything quietly broken?", answered from observable facts.
 *
 * The whole panel exists so the operator never has to SSH in to find out. On
 * this host the two things most likely to fail silently are the nightly backup
 * (a cron shell script the app cannot see, because exec() is disabled) and the
 * queue (cron-drained, with no worker and no dashboard). Neither announces
 * itself when it stops.
 *
 * Playbook §9 governs what appears here: every signal is a real measurement of
 * an observable outcome. Nothing is inferred, and where a fact cannot be known
 * this says so rather than showing a reassuring green tick.
 */
class PlatformHealth
{
    /** @return array<int,array{key:string,label:string,tone:string,value:string,detail:string}> */
    public function checks(): array
    {
        return [
            $this->backups(),
            $this->scheduler(),
            $this->failedJobs(),
            $this->pendingPurges(),
        ];
    }

    /**
     * OPS-01 — does a recent, plausibly-sized dump exist?
     *
     * The same check `osms:monitor-backups` alerts on, shown rather than emailed.
     * It catches every failure mode at once: script error, cron deleted, disk
     * full, wrong path, truncated dump.
     */
    private function backups(): array
    {
        $dir = $this->backupDir();

        if ($dir === '' || ! is_dir($dir)) {
            return $this->check('backups', 'Database backup', 'red', 'Not found',
                $dir !== '' ? "No directory at {$dir}" : 'Could not resolve the backup directory.');
        }

        $files = glob(rtrim($dir, '/') . '/osms_*.sql.gz') ?: [];

        if ($files === []) {
            return $this->check('backups', 'Database backup', 'red', 'None',
                "Nothing matching osms_*.sql.gz in {$dir}.");
        }

        $newestMtime = max(array_map(fn ($f) => @filemtime($f) ?: 0, $files));
        $newest = collect($files)->firstWhere(fn ($f) => (@filemtime($f) ?: 0) === $newestMtime) ?? $files[0];

        $ageHours = (time() - $newestMtime) / 3600;
        $size = (int) (@filesize($newest) ?: 0);
        $maxAge = max(1, (int) config('saas.backup_max_age_hours', 26));
        $minBytes = max(0, (int) config('saas.backup_min_bytes', 1024));

        $detail = sprintf('%s · %s · %d retained',
            basename((string) $newest), $this->bytes($size), count($files));

        if ($ageHours > $maxAge) {
            return $this->check('backups', 'Database backup', 'red',
                sprintf('%.0fh old', $ageHours), "Older than the {$maxAge}h limit. {$detail}");
        }

        if ($size < $minBytes) {
            return $this->check('backups', 'Database backup', 'amber', 'Suspiciously small',
                "Only {$this->bytes($size)} — a truncated dump looks exactly like this. {$detail}");
        }

        return $this->check('backups', 'Database backup', 'green',
            $ageHours < 1 ? 'Just now' : sprintf('%.0fh ago', $ageHours), $detail);
    }

    /**
     * Is cron alive? Read from the heartbeat the scheduler stamps itself.
     *
     * Before the first stamp exists this reports "unknown", not "healthy" —
     * a check that cannot tell the difference between working and never-run is
     * worse than no check.
     */
    private function scheduler(): array
    {
        $stamp = PlatformSetting::get(PlatformSetting::SCHEDULER_HEARTBEAT);

        if (! $stamp) {
            return $this->check('scheduler', 'Scheduler', 'neutral', 'Unknown',
                'No heartbeat recorded yet. It appears within five minutes of the cron running.');
        }

        $at = Carbon::parse($stamp);
        $minutes = $at->diffInMinutes(now());

        // Twice the five-minute interval, so one missed tick is not an alarm.
        return $minutes <= 15
            ? $this->check('scheduler', 'Scheduler', 'green', 'Running', 'Last check-in ' . $at->diffForHumans())
            : $this->check('scheduler', 'Scheduler', 'red', 'Stalled',
                'Last check-in ' . $at->diffForHumans() . '. Renewals, reminders and queued mail are not running.');
    }

    /** OPS-02 — the cron-drained queue has no dashboard, so failures are invisible. */
    private function failedJobs(): array
    {
        $failed = DB::table('failed_jobs')->count();
        $stuckMessages = DB::table('whatsapp_messages')->where('status', 'failed')->count();

        $detail = $stuckMessages > 0
            ? "{$stuckMessages} WhatsApp " . str('message')->plural($stuckMessages) . ' also failed to send.'
            : 'Nothing in the failed queue.';

        return $failed === 0
            ? $this->check('jobs', 'Background jobs', $stuckMessages > 0 ? 'amber' : 'green',
                $stuckMessages > 0 ? 'Sends failing' : 'Clear', $detail)
            : $this->check('jobs', 'Background jobs', 'amber', "{$failed} failed",
                'Queued mail and messages may not have gone out. ' . $detail);
    }

    /** P5 / row 16 — closed stores whose retention window has run out. */
    private function pendingPurges(): array
    {
        $due = Tenant::where('store_status', 'closed')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->count();

        $waiting = Tenant::where('store_status', 'closed')
            ->where(fn ($q) => $q->whereNull('purge_after')->orWhere('purge_after', '>', now()))
            ->count();

        // Nothing here is automatic. Data is destroyed when an operator decides
        // to destroy it, never on a timer — the window grants permission, it
        // does not schedule an execution.
        return $due === 0
            ? $this->check('closures', 'Closed stores', 'green',
                $waiting === 0 ? 'None' : "{$waiting} in retention",
                $waiting === 0 ? 'No stores are closed.' : 'Still restorable — nothing can be deleted yet.')
            : $this->check('closures', 'Closed stores', 'amber', "{$due} ready to delete",
                'Their retention window has passed. Deleting is still a manual decision.');
    }

    /** Configured directory, else $HOME/backups (matching scripts/backup-db.sh). */
    private function backupDir(): string
    {
        $configured = trim((string) config('saas.backup_dir', ''));

        if ($configured !== '') {
            return $configured;
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';

        return $home !== '' ? rtrim($home, '/\\') . '/backups' : '';
    }

    private function bytes(int $n): string
    {
        return $n >= 1048576
            ? number_format($n / 1048576, 1) . ' MB'
            : number_format($n / 1024, 1) . ' KB';
    }

    private function check(string $key, string $label, string $tone, string $value, string $detail): array
    {
        return compact('key', 'label', 'tone', 'value', 'detail');
    }
}
