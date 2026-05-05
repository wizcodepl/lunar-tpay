<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use WizcodePl\LunarTpay\Tests\TestCase;
use WizcodePl\LunarTpay\TpayClient;

/**
 * End-to-end against the real tpay sandbox. No mocks — real OAuth, real
 * `POST /transactions`, real `GET /transactions/{id}`.
 *
 * Skipped automatically when TPAY_CLIENT_ID / TPAY_CLIENT_SECRET aren't in env,
 * so CI without secrets stays green. Run locally with:
 *
 *     export TPAY_CLIENT_ID=...
 *     export TPAY_CLIENT_SECRET=...
 *     composer test
 */
#[Group('e2e')]
class TpayClientTest extends TestCase
{
    private TpayClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNoSandboxCreds();

        $this->client = new TpayClient(
            clientId: (string) getenv('TPAY_CLIENT_ID'),
            clientSecret: (string) getenv('TPAY_CLIENT_SECRET'),
            apiBaseUrl: 'https://openapi.sandbox.tpay.com',
        );
    }

    public function test_authenticates_and_creates_transaction_against_sandbox(): void
    {
        $response = $this->client->createTransaction([
            'amount' => 1.00,
            'description' => 'lunar-tpay e2e auth test',
            'lang' => 'pl',
            'payer' => [
                'email' => 'test@wizcode.pl',
                'name' => 'Test Payer',
            ],
            'callbacks' => [
                'payerUrls' => [
                    'success' => 'https://example.test/ok',
                    'error' => 'https://example.test/err',
                ],
                'notification' => [
                    'url' => 'https://example.test/tpay/notify',
                ],
            ],
        ]);

        $this->assertNotEmpty($response['transactionId'] ?? null);
        $this->assertNotEmpty($response['transactionPaymentUrl'] ?? null);
        $this->assertSame('success', strtolower((string) ($response['result'] ?? '')));
    }

    public function test_round_trips_a_transaction_via_get(): void
    {
        $created = $this->client->createTransaction([
            'amount' => 9.99,
            'description' => 'lunar-tpay e2e round trip',
            'hiddenDescription' => 'order-test-1',
            'lang' => 'pl',
            'payer' => ['email' => 'test@wizcode.pl', 'name' => 'Round Trip'],
            'callbacks' => [
                'payerUrls' => [
                    'success' => 'https://example.test/ok',
                    'error' => 'https://example.test/err',
                ],
                'notification' => ['url' => 'https://example.test/tpay/notify'],
            ],
        ]);

        $tpayId = (string) $created['transactionId'];

        $fetched = $this->client->getTransaction($tpayId);

        $this->assertSame($tpayId, (string) ($fetched['transactionId'] ?? ''));
        $this->assertEquals('9.99', (string) ($fetched['amount'] ?? ''));
        $this->assertSame('order-test-1', (string) ($fetched['hiddenDescription'] ?? ''));
    }

    public function test_throws_runtime_exception_on_bad_payload(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tpay createTransaction failed');

        // Missing required `amount` and `description` — tpay should reject.
        $this->client->createTransaction(['lang' => 'pl']);
    }
}
