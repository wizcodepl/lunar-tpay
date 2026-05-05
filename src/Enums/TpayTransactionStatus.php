<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Enums;

/**
 * Lifecycle of a `TpayTransaction` row.
 *
 *   Pending           — row opened, `POST /transactions` not yet attempted.
 *   CreateFailed      — `POST /transactions` threw / returned an error.
 *   RedirectPending   — tpay returned a transaction id + redirect URL; we are
 *                       waiting for the customer to come back and the webhook
 *                       to fire.
 *   Paid              — webhook reports a successful payment.
 *   Failed            — webhook reports the bank declined / customer aborted.
 *   Refunded          — webhook reports a refund or chargeback.
 *   Cancelled         — webhook reports the transaction was cancelled.
 */
enum TpayTransactionStatus: string
{
    case Pending = 'pending';
    case CreateFailed = 'create_failed';
    case RedirectPending = 'redirect_pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
}
