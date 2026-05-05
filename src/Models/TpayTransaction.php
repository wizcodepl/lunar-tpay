<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Base\BaseModel;
use Lunar\Models\Order;
use WizcodePl\LunarTpay\Enums\TpayTransactionStatus;

/**
 * Append-only audit log of tpay transactions opened from this shop.
 *
 * One row per `Payments::driver('tpay')->...->authorize()` call.
 * Updated by:
 *   - the driver itself (after `POST /transactions` returns) to set
 *     `tpay_transaction_id` and `redirect_url`;
 *   - the webhook controller (on every notification) to advance `status` and
 *     append the latest event to `last_event`.
 *
 * Lookups: by `tpay_transaction_id` (webhook → row), by `order_id` (admin →
 * history), by `status` (queues / dashboards).
 */
class TpayTransaction extends BaseModel
{
    protected $fillable = [
        'order_id',
        'tpay_transaction_id',
        'status',
        'amount',
        'currency',
        'redirect_url',
        'last_event',
        'last_notification_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'last_event' => 'array',
            'last_notification_at' => 'datetime',
            'status' => TpayTransactionStatus::class,
        ];
    }

    public function getTable()
    {
        return config('lunar.database.table_prefix').'tpay_transactions';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
