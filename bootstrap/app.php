<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ST-Harden (S8) — baseline security headers on every web response.
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            // SEC-05 — hold a half-authenticated session on the 2FA challenge.
            \App\Http\Middleware\EnforceTwoFactor::class,
            // P5 — "view as store" is read-only, enforced on every route rather
            // than on the ones we remembered to protect.
            \App\Http\Middleware\ReadOnlyImpersonation::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserRole::class,
            'superadmin' => \App\Http\Middleware\EnsureSuperadmin::class,
            'onboarded' => \App\Http\Middleware\EnsureTenantOnboarded::class,
            'subscribed' => \App\Http\Middleware\EnsureSubscriptionActive::class,
            'verified.optional' => \App\Http\Middleware\EnsureEmailVerifiedIfRequired::class,
        ]);

        // Razorpay posts webhooks without a CSRF token.
        $middleware->validateCsrfTokens(except: [
            'webhooks/razorpay',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // OPS — report unhandled exceptions to Sentry when a DSN is configured.
        // With SENTRY_LARAVEL_DSN empty (the default) this is a silent no-op, so
        // the app is unaffected until error tracking is turned on in production.
        Integration::handles($exceptions);
    })->create();
