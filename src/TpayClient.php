<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin facade over the Tpay OpenAPI. Only the endpoints we actually use
 * (token + create/get transaction) are wrapped.
 *
 * Tpay SDK ref: https://github.com/tpay-com/tpay-openapi-php
 * API docs:     https://docs-api.tpay.com/en/
 */
class TpayClient
{
    private const PROD_BASE_URL = 'https://openapi.tpay.com';

    private const SANDBOX_BASE_URL = 'https://openapi.sandbox.tpay.com';

    private ?string $accessToken = null;

    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $sandbox = true,
    ) {}

    /**
     * Create a new transaction and return its decoded JSON payload.
     *
     * @param array<string, mixed> $payload See https://docs-api.tpay.com/en/single-transaction/create-transaction/
     * @return array<string, mixed>
     */
    public function createTransaction(array $payload): array
    {
        $response = Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post('/transactions', $payload);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Tpay createTransaction failed (%d): %s',
                $response->status(),
                $response->body(),
            ));
        }

        return $response->json() ?? [];
    }

    /**
     * Look up a transaction by its tpay-side ID.
     *
     * @return array<string, mixed>
     */
    public function getTransaction(string $transactionId): array
    {
        $response = Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->acceptJson()
            ->timeout(15)
            ->get("/transactions/{$transactionId}");

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Tpay getTransaction failed (%d): %s',
                $response->status(),
                $response->body(),
            ));
        }

        return $response->json() ?? [];
    }

    /**
     * OAuth2 client_credentials flow. Tokens last 7200s — we cache for the
     * lifetime of this instance with a 60s safety buffer.
     */
    private function accessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiresAt - 60) {
            return $this->accessToken;
        }

        $response = Http::baseUrl($this->baseUrl())
            ->asForm()
            ->acceptJson()
            ->timeout(15)
            ->post('/oauth/auth', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Tpay token request failed (%d): %s',
                $response->status(),
                $response->body(),
            ));
        }

        $data = $response->json() ?? [];
        $this->accessToken = (string) ($data['access_token'] ?? '');
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 7200);

        if ($this->accessToken === '') {
            throw new RuntimeException('Tpay token response missing access_token');
        }

        return $this->accessToken;
    }

    private function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE_URL : self::PROD_BASE_URL;
    }
}
