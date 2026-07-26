<?php

namespace App\Providers;

use App\Services\WhatsApp\LogDriver;
use App\Services\WhatsApp\MetaCloudDriver;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
