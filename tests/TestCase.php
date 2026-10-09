<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** One directory per test process, holding a single stand-in database dump. */
    private static ?string $backupDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provideFreshBackup();
    }

    /**
     * feat-billing P.1 / S-6 - a store purge refuses unless a recent database backup
     * exists. A healthy production has one, so the test environment does too; the
     * tests that exercise the REFUSAL point `saas.backup_dir` somewhere empty or stale.
     *
     * It is a real file in a real directory, read by the real `BackupStatus` -
     * nothing is mocked, so the guard under test is the guard that ships.
     */
    protected function provideFreshBackup(): void
    {
        if (self::$backupDir === null) {
            self::$backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'osms-test-backups-' . getmypid();

            if (! is_dir(self::$backupDir)) {
                mkdir(self::$backupDir, 0777, true);
            }

            // Leave nothing behind in the temp folder once the run is over.
            $dir = self::$backupDir;
            register_shutdown_function(static function () use ($dir) {
                foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
            });
        }

        $dump = self::$backupDir . DIRECTORY_SEPARATOR . 'osms_test.sql.gz';

        if (! is_file($dump) || (time() - (int) filemtime($dump)) > 600) {
            file_put_contents($dump, str_repeat('-- stand-in dump --', 100)); // > backup_min_bytes
            touch($dump);
        }

        config(['saas.backup_dir' => self::$backupDir]);
    }
}
