<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 * The webhook controller acks tpay with `TRUE` immediately (so retries
 * don't pile up if listeners are slow) and dispatches this job to do the
 * actual work: apply the status, record the audit row, fire domain
 * events.
 *
 * Concurrency: per-order `Cache::lock` serializes notifications for the
 * same order across worker processes. Without it, two workers could read
 * `previousStatus = RedirectPending` simultaneously and both dispatch a
 * domain event — listeners (mail, fulfilment) would fire twice.
 *
 * Atomicity: the order update + audit row are wrapped in a `DB::transaction`
 * so a partial failure (e.g. order updated, audit row write threw) rolls
 * back. Job retry then re-runs cleanly under idempotency.
 *
 * Idempotency: tpay re-sends the same notification until it gets `TRUE`,
 * and may re-send a few times anyway. We compare the previous transaction
 * status with the resolved one — if it's the same terminal state, we skip
 * dispatching domain events.
 */
class ProcessTpayNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Lock TTL — how long Cache::lock holds before being considered stale
     * and forcefully released. Job should normally finish in a fraction of
     * a second; 30s is a generous safety margin against runaway listeners.
     */
    private const LOCK_TTL_SECONDS = 30;

    /**
     * How long a queued job will block waiting for the lock before giving
     * up. Less than the queue's job timeout to avoid thrashing — if we
     * wait this long, the worker holding the lock is doing something
     * unusual; better to fail and let the queue retry.
     */
    private const LOCK_BLOCK_SECONDS = 10;

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
        $lockKey = sprintf('lunar-tpay:order:%d', $this->order->id);

        Cache::lock($lockKey, self::LOCK_TTL_SECONDS)
            ->block(self::LOCK_BLOCK_SECONDS, function () use ($updateOrder, $recordEvent) {
                // Refresh inside the lock — another worker may have updated
                // since this job was serialized into the queue.
                $order = $this->order->fresh() ?? $this->order;

                $previousStatus = $this->previousTransactionStatus();

                /** @var array{0: TpayTransactionStatus, 1: TpayTransaction} $result */
                $result = DB::transaction(function () use ($updateOrder, $recordEvent, $order) {
                    $resolvedStatus = $updateOrder($order, $this->payload);
                    $transaction = $recordEvent($order, $this->payload, $resolvedStatus, $this->rawBody);

                    return [$resolvedStatus, $transaction];
                });

                [$resolvedStatus, $transaction] = $result;

                // Idempotency: skip event dispatch if we've already settled
                // in this state. This also covers the downgrade-rejected
                // path — UpdateOrderFromTpayStatus mirrors the current
                // order status back, which equals previousStatus.
                if ($previousStatus === $resolvedStatus && $this->isTerminal($resolvedStatus)) {
                    return;
                }

                match ($resolvedStatus) {
                    TpayTransactionStatus::Paid => TpayPaymentReceived::dispatch($order, $transaction),
                    TpayTransactionStatus::Refunded => TpayPaymentRefunded::dispatch($order, $transaction),
                    TpayTransactionStatus::Cancelled, TpayTransactionStatus::Failed => TpayPaymentCancelled::dispatch($order, $transaction),
                    default => null,
                };
            });
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
