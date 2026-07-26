<?php

namespace App\Providers;

use App\Services\WhatsApp\LogDriver;
use App\Services\WhatsApp\MetaCloudDriver;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // FT-WhatsApp — resolve the send driver by config. `log` (default) never
        // touches the network; `meta` calls the WhatsApp Cloud API.
        $this->app->bind(WhatsAppGateway::class, fn () => match (config('whatsapp.driver')) {
            'meta' => new MetaCloudDriver(),
            default => new LogDriver(),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Bootstrap 5 markup (matches the UI kit), then our own compact window —
        // the stock view renders ~11 page links, which swamps a 75-page list.
        Paginator::useBootstrapFive();
        Paginator::defaultView('pagination::osms');

        $this->hardenForProduction();
        $this->enforceLegacyReadOnly();
    }

    /**
     * Make the `legacy` connection physically incapable of writing.
     *
     * The migration reads the customer's OLD system directly. The right control
     * would be a MySQL user with SELECT only — but Hostinger's shared plans
     * enforce a 1:1 database-to-user ratio and block CREATE USER / GRANT, so the
     * connection has to reuse credentials that hold full privileges on that
     * database. Since the database cannot enforce read-only, the application
     * does: every statement is inspected before it runs and anything that is not
     * a SELECT is refused.
     *
     * A code-level guard is weaker than a grant — code can be changed — but it
     * is the strongest control this hosting plan permits, and an accidental
     * UPDATE or DROP against a live customer's old system is exactly the kind of
     * mistake that cannot be undone.
     *
     * See _artifacts/FirstCustomerFiles/LEGACY_DB_ACCESS.md.
     */
    protected function enforceLegacyReadOnly(): void
    {
        // Only wire it up when the connection is actually configured, so an
        // unconfigured install doesn't try to resolve it at boot.
        if (! config('database.connections.legacy.database')) {
            return;
        }

        DB::connection('legacy')->beforeExecuting(function (string $query): void {
            // Strip leading comments/whitespace before deciding — "/* x */ DROP"
            // must not read as harmless.
            $sql = ltrim(preg_replace('#^(\s|/\*.*?\*/|--[^\n]*\n)+#s', '', $query) ?? $query);

            if (! preg_match('/^(select|show|describe|explain)\b/i', $sql)) {
                throw new RuntimeException(
                    'The legacy connection is read-only: only SELECT is permitted. Refused: '
                    . Str::limit($sql, 80)
                );
            }
        });
    }

    /**
     * SEC-01 — production transport hardening, enforced in CODE (not left to an
     * env file that is gitignored and easy to forget on the server):
     *   • every generated URL is https, so a stray http link can't leak a session;
     *   • the session cookie is marked Secure (never sent over plain http).
     * Guarded to production so local http dev and the test suite are unaffected.
     * `SESSION_ENCRYPT=true` is set in .env.prod (encryption is deploy-time, since
     * it invalidates existing sessions once — see the deploy runbook).
     */
    protected function hardenForProduction(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        URL::forceScheme('https');
        config(['session.secure' => true]);
    }
}
