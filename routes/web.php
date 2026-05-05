<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use WizcodePl\LunarTpay\Http\Controllers\TpayWebhookController;
use WizcodePl\LunarTpay\Http\Middleware\VerifyTpayJws;

Route::post(
    config('lunar-tpay.webhook_path', 'tpay/notify'),
    TpayWebhookController::class,
)
    ->name('lunar-tpay.notify')
    ->middleware(VerifyTpayJws::class)
    ->withoutMiddleware([VerifyCsrfToken::class]);
