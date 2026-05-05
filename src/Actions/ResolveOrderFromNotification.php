<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Actions;

use Lunar\Models\Order;

/**
 * Maps an incoming tpay notification back to a Lunar Order.
 *
 * Primary lookup: `hiddenDescription` (set to the Order id when we created
 * the tpay transaction). Fallback: stored `meta.tpay.transaction_id` in case
 * the description was lost or rewritten upstream.
 */
class ResolveOrderFromNotification
{
    /**
     * @param array{transactionId: string, status: string, amount: string, order_id: string} $payload
     */
    public function __invoke(array $payload): ?Order
    {
        if ($payload['order_id'] !== '' && ctype_digit($payload['order_id'])) {
            $order = Order::find((int) $payload['order_id']);
            if ($order) {
                return $order;
            }
        }

        if ($payload['transactionId'] === '') {
            return null;
        }

        return Order::query()
            ->whereJsonContains('meta->tpay->transaction_id', $payload['transactionId'])
            ->first();
    }
}
