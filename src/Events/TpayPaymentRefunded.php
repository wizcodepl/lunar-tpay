<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lunar\Models\Order;
use WizcodePl\LunarTpay\Models\TpayTransaction;

/**
 * Dispatched when a tpay webhook reports a refund or chargeback. The Lunar
 * Order has been moved to `refunded`; listeners may want to notify the
 * customer, kick off a return / restock workflow, alert finance, etc.
 */
class TpayPaymentRefunded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly TpayTransaction $transaction,
    ) {}
}
