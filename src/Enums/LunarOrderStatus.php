<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Enums;

/**
 * Lunar order statuses that this package transitions an Order into.
 * Mirrors the strings Lunar stores in `orders.status` — Lunar core
 * does not expose them as an enum, so we maintain a private one here
 * for type safety in `match` / `switch`.
 *
 * Keep in sync with Lunar core. New statuses introduced upstream that
 * are not relevant to payment flow do not need to be reflected here.
 */
enum LunarOrderStatus: string
{
    case AwaitingPayment = 'awaiting-payment';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
}
