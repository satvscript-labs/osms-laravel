<?php

namespace Tests\Feature;

use App\Mail\FailedJobsAlertMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OPS-02 — the failed-job monitor. On a cron-only queue with no dashboard, a failed
 * background job is otherwise invisible; this command surfaces it to the superadmin.
 */
class Phase46OpsMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function seedFailedJob(string $queue = 'default'): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => '{}',
            'exception' => "RuntimeException: something broke\n#0 ...",
            'failed_at' => now(),
        ]);
    }

    public function test_reports_clean_when_there_are_no_failed_jobs(): void
    {
        Mail::fake();

        $this->artisan('osms:monitor-failed-jobs')
            ->expectsOutputToContain('No failed jobs')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_alerts_superadmins_when_a_job_has_failed(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'superadmin', 'email' => 'owner@osms.test']);
        User::factory()->create(['role' => 'store_admin', 'email' => 'store@osms.test']);

        $this->seedFailedJob('mail');

        $this->artisan('osms:monitor-failed-jobs')->assertExitCode(0);

        Mail::assertSent(FailedJobsAlertMail::class, function (FailedJobsAlertMail $mail) {
            return $mail->count === 1
                && $mail->hasTo('owner@osms.test')
                && ! $mail->hasTo('store@osms.test'); // superadmins only
        });
    }

    public function test_does_not_fail_when_there_is_no_superadmin_to_notify(): void
    {
        Mail::fake();
        $this->seedFailedJob();

        // No superadmin exists — the command must still succeed (log-only).
        $this->artisan('osms:monitor-failed-jobs')->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
