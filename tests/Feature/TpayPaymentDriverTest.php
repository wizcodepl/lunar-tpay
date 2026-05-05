<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Database\Factories\OrderFactory;
use Lunar\Facades\Payments;
use Lunar\Models\Currency;
use PHPUnit\Framework\Attributes\Group;
use WizcodePl\LunarTpay\Tests\TestCase;
use WizcodePl\LunarTpay\TpayPaymentDriver;

/**
 * Tests the driver against a real tpay sandbox transaction creation.
 * We use OrderFactory directly — full Cart→Order flow is Lunar's domain;
 * our concern is "given an Order, does authorize() create a tpay transaction
 * and persist its metadata".
 */
#[Group('e2e')]
class TpayPaymentDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_is_registered_in_lunar_payment_manager(): void
    {
        $driver = Payments::driver('tpay');

        $this->assertInstanceOf(TpayPaymentDriver::class, $driver);
    }

    public function test_authorize_creates_tpay_transaction_and_persists_metadata(): void
    {
        $this->skipIfNoSandboxCreds();

        // Lunar's Order.total cast requires a Currency in scope — minimum seed.
        Currency::factory()->create([
            'code' => 'PLN',
            'default' => true,
            'enabled' => true,
            'exchange_rate' => 1,
            'decimal_places' => 2,
        ]);

        $order = OrderFactory::new()->create([
            'total' => 1500,
            'sub_total' => 1500,
        ]);

        $result = Payments::driver('tpay')->order($order)->authorize();

        $this->assertNotNull($result);
        $this->assertTrue(
            $result->success,
            sprintf('authorize() failed: %s', $result->message ?? '(no message)'),
        );
        $this->assertNotEmpty($result->message, 'expected redirect URL in PaymentAuthorize.message');
        $this->assertStringContainsString('tpay', $result->message);

        $order = $order->fresh();
        $this->assertNotEmpty($order->meta['tpay']['transaction_id'] ?? null);
        $this->assertNotEmpty($order->meta['tpay']['redirect_url'] ?? null);
    }

    public function test_authorize_returns_failure_when_no_cart_or_order(): void
    {
        $result = Payments::driver('tpay')->authorize();

        $this->assertNotNull($result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('without an order or cart', (string) $result->message);
    }
}
