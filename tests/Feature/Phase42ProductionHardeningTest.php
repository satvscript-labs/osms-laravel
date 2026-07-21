<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * SEC-01 — production transport hardening must be enforced in CODE, so a missing
 * env var on the server can't leave sessions sniffable. In production the app
 * forces https URLs and a Secure session cookie; local/testing is untouched.
 */
class Phase42ProductionHardeningTest extends TestCase
{
    public function test_production_forces_https_and_secure_session_cookie(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue(config('session.secure'), 'Session cookie must be Secure in production.');
        $this->assertStringStartsWith('https://', url('/'), 'URLs must be https in production.');
    }

    public function test_non_production_is_not_forced_to_https(): void
    {
        // Default test environment is "testing", not "production".
        (new AppServiceProvider($this->app))->boot();

        $this->assertNotSame(true, config('session.secure'),
            'Secure cookie must not be forced outside production (would break local http).');
    }
}
