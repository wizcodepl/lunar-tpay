<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lunar\Models\Order;
use WizcodePl\LunarTpay\Models\TpayTransaction;

/**
 * Dispatched when an authorize() call to tpay errors out — e.g. credentials
 * rejected, malformed payload, network failure. The transaction row is in
 * `create_failed` status; no Lunar Order status change happened.
 */
class TpayPaymentFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ?Order $order,
        public readonly TpayTransaction $transaction,
        public readonly string $reason,
    ) {}
}
