<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Tpay OpenAPI credentials
    |--------------------------------------------------------------------------
    |
    | Generate in Merchant Panel → Integration → API → Open API Keys.
    | Sandbox creds available at https://register.sandbox.tpay.com/.
    |
    */
    'client_id' => env('TPAY_CLIENT_ID', ''),
    'client_secret' => env('TPAY_CLIENT_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Base URLs
    |--------------------------------------------------------------------------
    |
    | Tpay splits its services across two hosts:
    |   - `api_base_url` — OpenAPI (OAuth + transactions). Default prod
    |     `https://openapi.tpay.com`. Sandbox: `https://openapi.sandbox.tpay.com`.
    |   - `cert_base_url` — JWS x509 cert / root CA distribution. Default prod
    |     `https://secure.tpay.com`. Sandbox: `https://secure.sandbox.tpay.com`.
    |
    | Both default to production. Override per-environment via env:
    |
    |   TPAY_API_BASE_URL=https://openapi.sandbox.tpay.com
    |   TPAY_CERT_BASE_URL=https://secure.sandbox.tpay.com
    |
    */
    'api_base_url' => env('TPAY_API_BASE_URL', 'https://openapi.tpay.com'),
    'cert_base_url' => env('TPAY_CERT_BASE_URL', 'https://secure.tpay.com'),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The webhook URL exposed to tpay. Defaults to /tpay/notify under the app.
    | Tpay calls it on every transaction state change.
    |
    | The customer-facing return URLs are passed per transaction; configure them
    | as the absolute URLs of your storefront's success/error pages.
    |
    */
    'webhook_path' => env('TPAY_WEBHOOK_PATH', 'tpay/notify'),

    'return_url_success' => env('TPAY_RETURN_URL_SUCCESS', ''),
    'return_url_error' => env('TPAY_RETURN_URL_ERROR', ''),

    /*
    |--------------------------------------------------------------------------
    | Driver name in Lunar
    |--------------------------------------------------------------------------
    |
    | The key used to register the driver in Lunar PaymentManager. Reference
    | this from your Lunar `lunar.payments.types` config.
    |
    */
    'driver' => 'tpay',
];
