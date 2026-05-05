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

        // Status downgrade guard — once an order is `paid`, only `refunded`
        // is a legitimate next state. Replays of older `cancelled` / `failed`
        // notifications (legitimately late, or maliciously replayed) must
        // not flip the order back. `refunded` orders are terminal too.
        if ($this->isDowngrade($order->status, $resolved)) {
            Log::channel((string) config('lunar-tpay.log_channel', 'stack'))->warning(
                'lunar-tpay | rejected notification that would downgrade an already-settled order',
                [
                    'order_id' => $order->id,
                    'current_order_status' => $order->status,
                    'reported_status' => $payload['status'],
                    'transactionId' => $payload['transactionId'],
                ],
            );

            $order->update([
                'meta' => array_merge((array) $order->meta, [
                    'tpay' => array_merge((array) ($order->meta['tpay'] ?? []), [
                        'rejected_status' => $payload['status'],
                        'rejected_at' => now()->toIso8601String(),
                    ]),
                ]),
            ]);

            // Reflect current order state back to caller — the job's
            // idempotency check then sees "no transition" and skips events.
            return $this->mirrorOrderStatus($order->status);
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
     * Order is "settled" (paid or refunded) and the incoming status would
     * move it to a non-allowed state. Allowed transitions:
     *   paid     → refunded (legit refund / chargeback)
     *   paid     → paid     (duplicate notification — let through, idempotent)
     *   refunded → refunded (duplicate notification — let through)
     */
    private function isDowngrade(string $currentOrderStatus, TpayTransactionStatus $incoming): bool
    {
        if ($currentOrderStatus === 'paid') {
            return $incoming !== TpayTransactionStatus::Paid
                && $incoming !== TpayTransactionStatus::Refunded;
        }

        if ($currentOrderStatus === 'refunded') {
            return $incoming !== TpayTransactionStatus::Refunded;
        }

        return false;
    }

    private function mirrorOrderStatus(string $currentOrderStatus): TpayTransactionStatus
    {
        return match ($currentOrderStatus) {
            'paid' => TpayTransactionStatus::Paid,
            'refunded' => TpayTransactionStatus::Refunded,
            default => TpayTransactionStatus::RedirectPending,
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
