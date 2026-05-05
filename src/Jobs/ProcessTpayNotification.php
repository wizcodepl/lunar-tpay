<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lunar\Models\Order;
use WizcodePl\LunarTpay\Actions\RecordTpayWebhookEvent;
use WizcodePl\LunarTpay\Actions\UpdateOrderFromTpayStatus;
use WizcodePl\LunarTpay\Enums\TpayTransactionStatus;
use WizcodePl\LunarTpay\Events\TpayPaymentCancelled;
use WizcodePl\LunarTpay\Events\TpayPaymentReceived;
use WizcodePl\LunarTpay\Events\TpayPaymentRefunded;
use WizcodePl\LunarTpay\Models\TpayTransaction;

/**
 * Async pipeline for a verified tpay webhook.
 *
 * The webhook controller acks tpay with `TRUE` immediately (so retries don't
 * pile up if listeners are slow) and dispatches this job to do the actual
 * work: apply the status, record the audit row, fire domain events.
 *
 * Idempotency: tpay re-sends the same notification until it gets `TRUE`,
 * and may re-send a few times anyway. We compare the previous transaction
 * status with the resolved one — if it's the same terminal state, we skip
 * dispatching domain events so listeners (mails, fulfilment kickoff) don't
 * fire twice.
 */
class ProcessTpayNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array{transactionId: string, status: string, amount: string, order_id: string} $payload
     * @param array<string, mixed> $rawBody
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $payload,
        public readonly array $rawBody,
    ) {}

    public function handle(
        UpdateOrderFromTpayStatus $updateOrder,
        RecordTpayWebhookEvent $recordEvent,
    ): void {
        $previousStatus = $this->previousTransactionStatus();

        $resolvedStatus = $updateOrder($this->order, $this->payload);
        $transaction = $recordEvent($this->order, $this->payload, $resolvedStatus, $this->rawBody);

        // Idempotency: skip event dispatch if we've already settled in this state.
        if ($previousStatus === $resolvedStatus && $this->isTerminal($resolvedStatus)) {
            return;
        }

        match ($resolvedStatus) {
            TpayTransactionStatus::Paid => TpayPaymentReceived::dispatch($this->order, $transaction),
            TpayTransactionStatus::Refunded => TpayPaymentRefunded::dispatch($this->order, $transaction),
            TpayTransactionStatus::Cancelled, TpayTransactionStatus::Failed => TpayPaymentCancelled::dispatch($this->order, $transaction),
            default => null,
        };
    }

    private function previousTransactionStatus(): ?TpayTransactionStatus
    {
        $existing = TpayTransaction::query()
            ->when(
                $this->payload['transactionId'] !== '',
                fn ($q) => $q->where('tpay_transaction_id', $this->payload['transactionId']),
                fn ($q) => $q->where('order_id', $this->order->id),
            )
            ->latest('id')
            ->first();

        return $existing?->status;
    }

    private function isTerminal(TpayTransactionStatus $status): bool
    {
        return in_array($status, [
            TpayTransactionStatus::Paid,
            TpayTransactionStatus::Refunded,
            TpayTransactionStatus::Cancelled,
            TpayTransactionStatus::Failed,
        ], true);
    }
}
