<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Actions;

use Illuminate\Support\Facades\Log;
use Lunar\Models\Order;
use WizcodePl\LunarTpay\Enums\TpayTransactionStatus;

/**
 * Translates the tpay-side status string carried by a webhook into the
 * matching `TpayTransactionStatus` and applies the corresponding Lunar
 * Order status to the given order.
 *
 * On a "paid" notification we additionally verify the reported amount
 * matches the order total (defense in depth — JWS already prevents
 * tampering, but a future integration mistake or upstream bug would be
 * caught here). A mismatch downgrades the result to `Failed` and the
 * order is left in `awaiting-payment` for manual review.
 *
 * Returned `TpayTransactionStatus` is used by the controller to decide
 * which domain event to dispatch (paid → received, refunded, cancelled,
 * failed, …).
 */
class UpdateOrderFromTpayStatus
{
    /**
     * @param array{transactionId: string, status: string, amount: string, order_id: string} $payload
     */
    public function __invoke(Order $order, array $payload): TpayTransactionStatus
    {
        $resolved = $this->resolveStatus($payload['status']);

        if ($resolved === TpayTransactionStatus::Paid && ! $this->amountMatches($order, $payload['amount'])) {
            Log::channel((string) config('lunar-tpay.log_channel', 'stack'))->warning(
                'lunar-tpay | paid notification with amount mismatch — refusing to mark as paid',
                [
                    'order_id' => $order->id,
                    'order_total' => (int) $order->total->value,
                    'reported_amount' => $payload['amount'],
                    'transactionId' => $payload['transactionId'],
                ],
            );

            $resolved = TpayTransactionStatus::Failed;
        }

        $order->update([
            'status' => $this->mapToOrderStatus($resolved),
            'meta' => array_merge((array) $order->meta, [
                'tpay' => array_merge((array) ($order->meta['tpay'] ?? []), [
                    'last_status' => $payload['status'],
                    'last_amount' => $payload['amount'],
                    'last_notification_at' => now()->toIso8601String(),
                ]),
            ]),
        ]);

        return $resolved;
    }

    private function resolveStatus(string $tpayStatus): TpayTransactionStatus
    {
        return match (strtoupper($tpayStatus)) {
            'PAID', 'CORRECT', 'TRUE' => TpayTransactionStatus::Paid,
            'CHARGEBACK', 'REFUND' => TpayTransactionStatus::Refunded,
            'CANCELLED', 'CANCELED' => TpayTransactionStatus::Cancelled,
            'FALSE', 'DECLINED', 'ERROR' => TpayTransactionStatus::Failed,
            default => TpayTransactionStatus::RedirectPending,
        };
    }

    private function mapToOrderStatus(TpayTransactionStatus $status): string
    {
        return match ($status) {
            TpayTransactionStatus::Paid => 'paid',
            TpayTransactionStatus::Refunded => 'refunded',
            TpayTransactionStatus::Cancelled, TpayTransactionStatus::Failed => 'cancelled',
            default => 'awaiting-payment',
        };
    }

    /**
     * Tpay reports amount as a decimal string ("15.00"). Lunar stores it as
     * minor units (integer cents). Compare with a 1-cent tolerance to absorb
     * floating-point rounding, but anything bigger is a real mismatch.
     */
    private function amountMatches(Order $order, string $reported): bool
    {
        if ($reported === '') {
            return false;
        }

        $reportedMinor = (int) round(((float) $reported) * 100);
        $expectedMinor = (int) $order->total->value;

        return abs($reportedMinor - $expectedMinor) <= 1;
    }
}
