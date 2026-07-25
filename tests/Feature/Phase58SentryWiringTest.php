<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * OPS — Sentry is wired for error tracking but must be a completely silent no-op
 * until a DSN is set, and must never send customer PII to a third party (DPDP).
 */
class Phase58SentryWiringTest extends TestCase
{
    public function test_the_app_boots_with_sentry_installed(): void
    {
        // A trivial request proves the bootstrap/app.php Integration::handles()
        // wiring didn't break the container when no DSN is configured.
        $this->get('/login')->assertOk();
    }

    public function test_sentry_is_disabled_by_default(): void
    {
        // No DSN in the test env → Sentry does nothing. This pins the safe default.
        $this->assertEmpty(config('sentry.dsn'));
    }

    public function test_customer_pii_is_never_sent_by_default(): void
    {
        // DPDP — patient/customer data must not leave for a third-party service.
        $this->assertFalse((bool) config('sentry.send_default_pii'));
    }
}
