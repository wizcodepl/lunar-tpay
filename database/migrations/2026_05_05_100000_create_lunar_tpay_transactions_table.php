<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lunar\Base\Migration;
use WizcodePl\LunarTpay\Enums\TpayTransactionStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->prefix.'tpay_transactions', function (Blueprint $table) {
            $table->id();

            // The Lunar Order this transaction belongs to. Nullable because the row
            // can be created before the Order is fully persisted; in practice it's
            // always set right after.
            $table->foreignId('order_id')
                ->nullable()
                ->constrained($this->prefix.'orders')
                ->nullOnDelete();

            // The tpay-side identifier (returned by `POST /transactions`). Indexed
            // for webhook lookups. Nullable until the API call succeeds.
            $table->string('tpay_transaction_id')->nullable()->index();

            // Lifecycle status: pending → redirect_pending → paid / cancelled / failed.
            // Backed by the `TpayTransactionStatus` enum.
            $table->string('status')->default(TpayTransactionStatus::Pending->value)->index();

            // Authorization amount + currency at the time the transaction was opened.
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('PLN');

            // Customer redirect URL returned by tpay (for post-checkout redirect).
            $table->text('redirect_url')->nullable();

            // Last raw event from tpay — request error message, webhook payload,
            // or any context useful for debugging. Stored as JSON for flexibility.
            $table->json('last_event')->nullable();

            $table->timestamp('last_notification_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->prefix.'tpay_transactions');
    }
};
