<?php

namespace Tests\Feature;

use App\Mail\BackupAlertMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * OPS-01 — the backup watchdog. The nightly dump is a cron-driven shell script the
 * app cannot observe, so this asserts the app correctly detects the OUTCOME:
 * missing directory, no backups, a stale backup, or a suspiciously small one —
 * and stays quiet when everything is healthy.
 */
class Phase53BackupMonitorTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/osms-backup-test-' . uniqid();
        mkdir($this->dir, 0777, true);
        config([
            'saas.backup_dir' => $this->dir,
            'saas.backup_max_age_hours' => 26,
            'saas.backup_min_bytes' => 1024,
        ]);
        User::factory()->create(['role' => 'superadmin', 'email' => 'boss@osms.test', 'tenant_id' => null]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Create a backup file of a given size and age. */
    private function backup(string $name, int $bytes = 4096, int $ageHours = 1): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, str_repeat('x', $bytes));
        touch($path, time() - (int) ($ageHours * 3600));

        return $path;
    }

    public function test_a_fresh_backup_raises_no_alert(): void
    {
        Mail::fake();
        $this->backup('osms_2026-07-21_0230.sql.gz', 40000, 3);

        $this->artisan('osms:monitor-backups')
            ->expectsOutputToContain('Backup healthy')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_a_stale_backup_alerts(): void
    {
        Mail::fake();
        // 3 days old — well past the 26h limit.
        $this->backup('osms_2026-07-18_0230.sql.gz', 40000, 72);

        $this->artisan('osms:monitor-backups')->assertSuccessful();

        Mail::assertSent(BackupAlertMail::class, fn ($m) => str_contains($m->problem, 'stale'));
    }

    public function test_no_backups_at_all_alerts(): void
    {
        Mail::fake();

        $this->artisan('osms:monitor-backups')->assertSuccessful();

        Mail::assertSent(BackupAlertMail::class, fn ($m) => str_contains($m->problem, 'no backups'));
    }

    public function test_a_missing_directory_alerts(): void
    {
        Mail::fake();
        config(['saas.backup_dir' => $this->dir . '-does-not-exist']);

        $this->artisan('osms:monitor-backups')->assertSuccessful();

        Mail::assertSent(BackupAlertMail::class, fn ($m) => str_contains($m->problem, 'directory not found'));
    }

    public function test_a_suspiciously_small_backup_alerts(): void
    {
        Mail::fake();
        $this->backup('osms_2026-07-21_0230.sql.gz', 50, 1); // 50 bytes — truncated

        $this->artisan('osms:monitor-backups')->assertSuccessful();

        Mail::assertSent(BackupAlertMail::class, fn ($m) => str_contains($m->problem, 'suspiciously small'));
    }

    public function test_the_newest_backup_decides_even_when_older_ones_exist(): void
    {
        Mail::fake();
        $this->backup('osms_2026-07-10_0230.sql.gz', 40000, 240); // ancient
        $this->backup('osms_2026-07-21_0230.sql.gz', 40000, 2);   // fresh

        $this->artisan('osms:monitor-backups')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_it_does_not_crash_without_a_superadmin(): void
    {
        Mail::fake();
        User::query()->delete();

        $this->artisan('osms:monitor-backups')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_it_is_registered_on_the_scheduler(): void
    {
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $found = collect($events)->contains(fn ($e) => str_contains($e->command ?? '', 'osms:monitor-backups'));

        $this->assertTrue($found, 'osms:monitor-backups must be scheduled or it will never run.');
    }
}
