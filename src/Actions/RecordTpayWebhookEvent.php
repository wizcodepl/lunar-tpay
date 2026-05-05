<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Actions;

use Lunar\Models\Order;
use WizcodePl\LunarTpay\Enums\TpayTransactionStatus;
use WizcodePl\LunarTpay\Models\TpayTransaction;

/**
 * Updates (or creates) the `TpayTransaction` audit row that corresponds to
 * the incoming webhook. Looks up by `tpay_transaction_id` first, then by
 * `order_id`. If we have neither match (e.g. the driver row was wiped) we
 * still create a row so the notification is not lost.
 */
class RecordTpayWebhookEvent
{
    /**
     * @param array{transactionId: string, status: string, amount: string, order_id: string} $payload
     * @param array<string, mixed> $rawBody
     */
    public function __invoke(
        ?Order $order,
        array $payload,
        TpayTransactionStatus $status,
        array $rawBody,
    ): TpayTransaction {
        $record = null;

        if ($payload['transactionId'] !== '') {
            $record = TpayTransaction::query()
                ->where('tpay_transaction_id', $payload['transactionId'])
                ->latest('id')
                ->first();
        }

        if (! $record && $order) {
            $record = TpayTransaction::query()
                ->where('order_id', $order->id)
                ->latest('id')
                ->first();
        }

        $attributes = [
            'status' => $status,
            'last_event' => ['notification' => $rawBody],
            'last_notification_at' => now(),
        ];

        if ($record) {
            $record->update($attributes);

            return $record->fresh() ?? $record;
        }

        return TpayTransaction::create(array_merge($attributes, [
            'order_id' => $order?->id,
            'tpay_transaction_id' => $payload['transactionId'] ?: null,
            'amount' => (int) round(((float) $payload['amount']) * 100),
            'currency' => (string) ($order?->currency_code ?: 'PLN'),
        ]));
    }
}
