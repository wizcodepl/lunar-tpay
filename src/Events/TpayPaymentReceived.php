<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lunar\Models\Order;
use WizcodePl\LunarTpay\Models\TpayTransaction;

/**
 * Dispatched when a tpay webhook confirms a payment as successful (status `paid`).
 * Listen for this to send confirmation emails, hand off to fulfilment, etc.
 */
class TpayPaymentReceived
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly TpayTransaction $transaction,
    ) {}
}
