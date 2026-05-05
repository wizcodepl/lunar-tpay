<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Lunar\Database\Factories\OrderFactory;
use Lunar\Models\Currency;
use PHPUnit\Framework\Attributes\Group;
use WizcodePl\LunarTpay\Jobs\ProcessTpayNotification;
use WizcodePl\LunarTpay\Tests\TestCase;
use WizcodePl\LunarTpay\TpayJwsVerifier;

/**
 * Controller-level tests: middleware enforcement, order lookup, ack body,
 * and the controller's hand-off to the queued job.
 *
 * The cryptographic JWS path is covered separately in TpayJwsVerifierTest;
 * here we swap the verifier with a stub so we can drive the controller
 * with arbitrary payloads.
 *
 * The job's actual work — status mapping, idempotency, amount sanity
 * check, domain event dispatch — has its own dedicated test
 * (ProcessTpayNotificationTest).
 */
#[Group('e2e')]
class TpayWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::factory()->create([
            'code' => 'PLN',
            'default' => true,
            'enabled' => true,
            'exchange_rate' => 1,
            'decimal_places' => 2,
        ]);
    }

    public function test_rejects_notification_without_jws_header(): void
    {
        $response = $this->postJson('/tpay/notify', [
            'transactionId' => 'TR000111',
            'status' => 'paid',
            'amount' => '15.00',
            'hiddenDescription' => '999',
        ]);

        $response->assertStatus(403);
        $this->assertSame('SIGNATURE_INVALID', $response->getContent());
    }

    public function test_acks_tpay_and_dispatches_processing_job(): void
    {
        Bus::fake();
        $this->bindVerifierStub(verified: true);

        $order = OrderFactory::new()->create([
            'total' => 1500,
            'sub_total' => 1500,
            'meta' => ['tpay' => ['transaction_id' => 'TR000222']],
        ]);

        $response = $this->postJson(
            '/tpay/notify',
            [
                'transactionId' => 'TR000222',
                'status' => 'paid',
                'amount' => '15.00',
                'hiddenDescription' => (string) $order->id,
            ],
            ['X-JWS-Signature' => 'header..signature'],
        );

        $response->assertStatus(200);
        $this->assertSame('TRUE', $response->getContent());

        Bus::assertDispatched(ProcessTpayNotification::class, fn ($job) => $job->order->is($order)
            && $job->payload['transactionId'] === 'TR000222'
            && $job->payload['status'] === 'paid');
    }

    public function test_rejects_when_jws_verifier_returns_false(): void
    {
        Bus::fake();
        $this->bindVerifierStub(verified: false);

        $order = OrderFactory::new()->create([
            'total' => 1500,
            'sub_total' => 1500,
            'meta' => ['tpay' => ['transaction_id' => 'TR000333']],
        ]);

        $response = $this->postJson(
            '/tpay/notify',
            [
                'transactionId' => 'TR000333',
                'status' => 'paid',
                'amount' => '15.00',
                'hiddenDescription' => (string) $order->id,
            ],
            ['X-JWS-Signature' => 'header..tampered-signature'],
        );

        $response->assertStatus(403);
        Bus::assertNotDispatched(ProcessTpayNotification::class);
    }

    public function test_returns_404_for_unknown_order(): void
    {
        Bus::fake();
        $this->bindVerifierStub(verified: true);

        $response = $this->postJson(
            '/tpay/notify',
            [
                'transactionId' => 'TR-NONEXISTENT',
                'status' => 'paid',
                'amount' => '1.00',
                'hiddenDescription' => '99999999',
            ],
            ['X-JWS-Signature' => 'header..signature'],
        );

        $response->assertStatus(404);
        Bus::assertNotDispatched(ProcessTpayNotification::class);
    }

    /**
     * Swap the JWS verifier in the container with a deterministic stub —
     * isolates controller logic from the cryptographic dependency.
     */
    private function bindVerifierStub(bool $verified): void
    {
        $this->app->instance(TpayJwsVerifier::class, new class($verified) extends TpayJwsVerifier
        {
            public function __construct(private readonly bool $verified)
            {
                parent::__construct(sandbox: true);
            }

            public function verify(string $jwsHeader, string $rawBody): bool
            {
                return $this->verified;
            }
        });
    }
}
