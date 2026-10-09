<?php

namespace App\Support;

/**
 * feat-billing P.1 / S-6 - "is there a recent, plausible database backup?"
 *
 * Irreversible actions (purging a store) ask this first, so that the way back
 * exists before the door is closed. It reads the SAME files and the SAME
 * thresholds as the backup monitor (`osms:monitor-backups`) and the Platform
 * page's backup tile (`osms_*.sql.gz`, `saas.backup_max_age_hours`,
 * `saas.backup_min_bytes`), so all three always agree about what "recent" means.
 *
 * The backup itself is made by `scripts/backup-db.sh` from cron: PHP cannot run
 * mysqldump on this host. This class only LOOKS at what that script produced.
 */
final class BackupStatus
{
    /**
     * Why there is no usable backup, or null when there is one.
     *
     * Deliberately returns the REASON, in words an operator can act on, rather
     * than a bare boolean: "no backup" and "a backup 40 hours old" call for
     * different responses.
     */
    public static function problem(): ?string
    {
        $dir = self::dir();

        if ($dir === '' || ! is_dir($dir)) {
            return $dir === ''
                ? 'The backup directory could not be located.'
                : "There is no backup directory at {$dir}.";
        }

        $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'osms_*.sql.gz') ?: [];

        if ($files === []) {
            return "No database backup (osms_*.sql.gz) was found in {$dir}.";
        }

        $newest = null;
        $newestMtime = 0;

        foreach ($files as $file) {
            $mtime = @filemtime($file) ?: 0;
            if ($mtime >= $newestMtime) {
                $newestMtime = $mtime;
                $newest = $file;
            }
        }

        $ageHours = (time() - $newestMtime) / 3600;
        $maxAge = max(1, (int) config('saas.backup_max_age_hours', 26));
        $minBytes = max(0, (int) config('saas.backup_min_bytes', 1024));
        $size = (int) (@filesize((string) $newest) ?: 0);

        if ($ageHours > $maxAge) {
            return sprintf('The newest backup (%s) is %.0f hours old; the limit is %d.', basename((string) $newest), $ageHours, $maxAge);
        }

        if ($size < $minBytes) {
            return sprintf('The newest backup (%s) is only %d bytes - a truncated dump looks exactly like this.', basename((string) $newest), $size);
        }

        return null;
    }

    /** Configured directory, else $HOME/backups (matching scripts/backup-db.sh). */
    public static function dir(): string
    {
        $configured = trim((string) config('saas.backup_dir', ''));

        if ($configured !== '') {
            return $configured;
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';

        return $home !== '' ? rtrim($home, '/\\') . '/backups' : '';
    }
}
