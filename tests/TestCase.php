<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Tests;

use Cartalyst\Converter\Laravel\ConverterServiceProvider;
use Kalnoy\Nestedset\NestedSetServiceProvider;
use Lunar\LunarServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelBlink\BlinkServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use WizcodePl\LunarTpay\LunarTpayServiceProvider;

/**
 * Base test case bootstrapping the minimum stack required for a Lunar plugin
 * — Lunar core + its transitive Laravel providers (Cartalyst Converter,
 * Spatie ActivityLog/MediaLibrary/Blink, Kalnoy NestedSet). Without these
 * Lunar's boot fails with a `Target class [converter] does not exist` style
 * error from the Converter facade in `LunarServiceProvider::boot()`.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ConverterServiceProvider::class,
            ActivitylogServiceProvider::class,
            MediaLibraryServiceProvider::class,
            BlinkServiceProvider::class,
            NestedSetServiceProvider::class,
            LunarServiceProvider::class,
            LunarTpayServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('lunar-tpay.client_id', env('TPAY_CLIENT_ID', ''));
        $app['config']->set('lunar-tpay.client_secret', env('TPAY_CLIENT_SECRET', ''));
        $app['config']->set('lunar-tpay.api_base_url', 'https://openapi.sandbox.tpay.com');
        $app['config']->set('lunar-tpay.cert_base_url', 'https://secure.sandbox.tpay.com');
        $app['config']->set('lunar-tpay.return_url_success', 'https://example.test/ok');
        $app['config']->set('lunar-tpay.return_url_error', 'https://example.test/err');
    }

    protected function skipIfNoSandboxCreds(): void
    {
        if (! getenv('TPAY_CLIENT_ID') || ! getenv('TPAY_CLIENT_SECRET')) {
            $this->markTestSkipped(
                'Set TPAY_CLIENT_ID and TPAY_CLIENT_SECRET in your shell to run sandbox e2e tests.'
            );
        }
    }
}
