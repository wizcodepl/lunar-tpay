<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Lunar\Database\Factories\OrderFactory;
use Lunar\Models\Currency;
use Lunar\Models\Order;
use PHPUnit\Framework\Attributes\Group;
use WizcodePl\LunarTpay\Actions\RecordTpayWebhookEvent;
use WizcodePl\LunarTpay\Actions\UpdateOrderFromTpayStatus;
use WizcodePl\LunarTpay\Enums\TpayTransactionStatus;
use WizcodePl\LunarTpay\Events\TpayPaymentCancelled;
use WizcodePl\LunarTpay\Events\TpayPaymentReceived;
use WizcodePl\LunarTpay\Events\TpayPaymentRefunded;
use WizcodePl\LunarTpay\Jobs\ProcessTpayNotification;
use WizcodePl\LunarTpay\Models\TpayTransaction;
use WizcodePl\LunarTpay\Tests\TestCase;

/**
 * Covers the post-ack pipeline: status mapping, audit row update, domain
 * event dispatch, idempotency and amount sanity check. Runs the job
 * synchronously (no real queue worker) — that's enough to assert behavior.
 */
#[Group('e2e')]
class ProcessTpayNotificationTest extends TestCase
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

    public function test_marks_order_paid_records_audit_row_and_dispatches_received_event(): void
    {
        Event::fake([TpayPaymentReceived::class]);

        $order = $this->orderWithExistingTransaction(amount: 1500, tpayId: 'TR-PAID-1');

        $this->runJob($order, [
            'transactionId' => 'TR-PAID-1',
            'status' => 'paid',
            'amount' => '15.00',
            'order_id' => (string) $order->id,
        ]);

        $this->assertSame('paid', $order->fresh()->status);
        $record = TpayTransaction::where('tpay_transaction_id', 'TR-PAID-1')->first();
        $this->assertSame(TpayTransactionStatus::Paid, $record?->status);

        Event::assertDispatched(TpayPaymentReceived::class, fn ($e) => $e->order->is($order));
    }

    public function test_idempotent_paid_does_not_double_dispatch(): void
    {
        Event::fake([TpayPaymentReceived::class]);

        $order = $this->orderWithExistingTransaction(amount: 1500, tpayId: 'TR-DUPE');

        $payload = [
            'transactionId' => 'TR-DUPE',
            'status' => 'paid',
            'amount' => '15.00',
            'order_id' => (string) $order->id,
        ];

        $this->runJob($order, $payload);
        $this->runJob($order, $payload);

        Event::assertDispatchedTimes(TpayPaymentReceived::class, 1);
    }

    public function test_amount_mismatch_on_paid_downgrades_to_failed_and_skips_received_event(): void
    {
        Event::fake([TpayPaymentReceived::class, TpayPaymentCancelled::class]);

        $order = $this->orderWithExistingTransaction(amount: 1500, tpayId: 'TR-WRONG-AMOUNT');

        $this->runJob($order, [
            'transactionId' => 'TR-WRONG-AMOUNT',
            'status' => 'paid',
            'amount' => '1.00', // expected 15.00
            'order_id' => (string) $order->id,
        ]);

        $this->assertNotSame('paid', $order->fresh()->status);
        Event::assertNotDispatched(TpayPaymentReceived::class);
        Event::assertDispatched(TpayPaymentCancelled::class);

        $record = TpayTransaction::where('tpay_transaction_id', 'TR-WRONG-AMOUNT')->first();
        $this->assertSame(TpayTransactionStatus::Failed, $record?->status);
    }

    public function test_chargeback_dispatches_refunded_event(): void
    {
        Event::fake([TpayPaymentRefunded::class]);

        $order = $this->orderWithExistingTransaction(amount: 1500, tpayId: 'TR-CB');

        $this->runJob($order, [
            'transactionId' => 'TR-CB',
            'status' => 'chargeback',
            'amount' => '15.00',
            'order_id' => (string) $order->id,
        ]);

        $this->assertSame('refunded', $order->fresh()->status);
        Event::assertDispatched(TpayPaymentRefunded::class);
    }

    public function test_paid_order_rejects_cancelled_replay(): void
    {
        // Regression: an attacker (or buggy upstream) replays an old `FALSE`
        // notification *after* the order has been settled as `paid`. We must
        // not flip the order back, and no Cancelled event must fire.
        Event::fake([TpayPaymentReceived::class, TpayPaymentCancelled::class]);

        $order = $this->orderWithExistingTransaction(amount: 1500, tpayId: 'TR-REPLAY');

        // First a legitimate paid notification — order becomes `paid`.
        $this->runJob($order, [
            'transactionId' => 'TR-REPLAY',
            'status' => 'paid',
            'amount' => '15.00',
            'order_id' => (string) $order->id,
        ]);
        $this->assertSame('paid', $order->fresh()->status);

        // Then the replayed `FALSE` (cancelled) — must be rejected.
        $this->runJob($order->fresh(), [
            'transactionId' => 'TR-REPLAY',
            'status' => 'FALSE',
            'amount' => '15.00',
            'order_id' => (string) $order->id,
        ]);

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->status, 'order must stay paid after replayed cancellation');
        $this->assertSame('FALSE', $fresh->meta['tpay']['rejected_status'] ?? null);
        Event::assertNotDispatched(TpayPaymentCancelled::class);
    }

    public function test_paid_order_still_allows_refund(): void
    {
        // The legitimate paid → refunded transition (real chargeback) must
        // pass through even after the downgrade guard is in place.
        Event::fake([TpayPaymentRefunded::class]);

        $order = $this->orderWithExistingTransaction(amount: 1500, tpayId: 'TR-REFUND-AFTER-PAID');

        $this->runJob($order, [
            'transactionId' => 'TR-REFUND-AFTER-PAID',
            'status' => 'paid',
            'amount' => '15.00',
            'order_id' => (string) $order->id,
        ]);
        $this->assertSame('paid', $order->fresh()->status);

        $this->runJob($order->fresh(), [
            'transactionId' => 'TR-REFUND-AFTER-PAID',
            'status' => 'CHARGEBACK',
            'amount' => '15.00',
            'order_id' => (string) $order->id,
        ]);

        $this->assertSame('refunded', $order->fresh()->status);
        Event::assertDispatched(TpayPaymentRefunded::class);
    }

    /**
     * @param array{transactionId: string, status: string, amount: string, order_id: string} $payload
     */
    private function runJob(Order $order, array $payload): void
    {
        (new ProcessTpayNotification($order, $payload, $payload))
            ->handle(
                app(UpdateOrderFromTpayStatus::class),
                app(RecordTpayWebhookEvent::class),
            );
    }

    private function orderWithExistingTransaction(int $amount, string $tpayId): Order
    {
        $order = OrderFactory::new()->create([
            'total' => $amount,
            'sub_total' => $amount,
            'meta' => ['tpay' => ['transaction_id' => $tpayId]],
        ]);

        TpayTransaction::create([
            'order_id' => $order->id,
            'tpay_transaction_id' => $tpayId,
            'status' => TpayTransactionStatus::RedirectPending,
            'amount' => $amount,
            'currency' => 'PLN',
        ]);

        return $order;
    }
}
