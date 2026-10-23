<?php

namespace Chooka321\Deluxe;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DeluxeServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Add package views to the finder so render('gateways.deluxe.*') resolves correctly
        $this->loadViewsFrom(
            __DIR__ . '/../../resources/views',
            'deluxe'
        );

        // Also register the package view path directly so Invoice Ninja's
        // render() helper (which prepends 'portal.ninja2020.') finds our views
        view()->addLocation(__DIR__ . '/../../resources/views');

        // Register the webhook route outside all middleware groups
        // This survives IN updates because it lives here, not in routes/api.php
        Route::post(
            'deluxe/webhook',
            [\App\PaymentDrivers\DeluxePaymentPlatform\WebhookHandler::class, 'handle']
        )->name('payment.deluxe.webhook');

        // Publish migration so the gateway gets registered in the DB
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'deluxe-migrations');

        // Inject CSS+JS into the admin SPA loader to restyle the
        // "Production Mode" Headless UI Switch as a square checkbox.
        // Registered globally because IN serves admin deep links via a
        // fallback route in client.php (only throttle middleware). The
        // middleware itself fast-skips any non-admin response.
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        if (method_exists($kernel, 'pushMiddleware')) {
            $kernel->pushMiddleware(\Chooka321\Deluxe\InjectAdminCheckbox::class);
        }
    }
}
