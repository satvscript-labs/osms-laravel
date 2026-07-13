<?php

namespace App\Providers;

use App\Services\WhatsApp\LogDriver;
use App\Services\WhatsApp\MetaCloudDriver;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Pagination\Paginator;
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
        // Render paginator links with Bootstrap 5 markup (matches the UI kit).
        Paginator::useBootstrapFive();
    }
}
