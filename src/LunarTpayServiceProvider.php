<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay;

use Illuminate\Support\ServiceProvider;
use Lunar\Facades\Payments;

class LunarTpayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lunar-tpay.php', 'lunar-tpay');

        $this->app->singleton(TpayClient::class, function () {
            return new TpayClient(
                clientId: (string) config('lunar-tpay.client_id'),
                clientSecret: (string) config('lunar-tpay.client_secret'),
                sandbox: (bool) config('lunar-tpay.sandbox', true),
            );
        });

        $this->app->singleton(TpayJwsVerifier::class, function () {
            return new TpayJwsVerifier(
                sandbox: (bool) config('lunar-tpay.sandbox', true),
            );
        });
    }

    public function boot(): void
    {
        // Register the driver as `tpay` in Lunar PaymentManager.
        Payments::extend(
            (string) config('lunar-tpay.driver', 'tpay'),
            fn () => $this->app->make(TpayPaymentDriver::class),
        );

        // Webhook route — tpay POSTs notifications here on every state change.
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Migrations create `lunar_tpay_transactions` (audit log of every authorize).
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/lunar-tpay.php' => config_path('lunar-tpay.php'),
        ], 'lunar-tpay-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'lunar-tpay-migrations');
    }
}
