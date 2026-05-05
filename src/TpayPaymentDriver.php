<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay;

use Lunar\Base\DataTransferObjects\PaymentAuthorize;
use Lunar\Base\DataTransferObjects\PaymentCapture;
use Lunar\Base\DataTransferObjects\PaymentRefund;
use Lunar\Events\PaymentAttemptEvent;
use Lunar\Models\Contracts\Transaction as TransactionContract;
use Lunar\Models\Order;
use Lunar\PaymentTypes\AbstractPayment;
use Throwable;
use WizcodePl\LunarTpay\Enums\TpayTransactionStatus;
use WizcodePl\LunarTpay\Events\TpayPaymentFailed;
use WizcodePl\LunarTpay\Models\TpayTransaction;

/**
 * Tpay payment driver for Lunar.
 *
 * Flow:
 *   1. authorize() — creates the Lunar Order (if missing), POSTs a transaction
 *      to tpay OpenAPI, stores the tpay transactionId on Order.meta and returns
 *      the redirect URL via PaymentAuthorize.message. The frontend redirects the
 *      customer there to complete payment.
 *   2. tpay calls our webhook (POST /tpay/notify) on every state change.
 *      The TpayWebhookController updates Order.status to `paid` / `cancelled`.
 *   3. capture() is a no-op success — final state is decided by the webhook.
 *   4. refund() is a no-op (refunds are out of MVP scope).
 */
class TpayPaymentDriver extends AbstractPayment
{
    public function __construct(
        private readonly TpayClient $client,
    ) {}

    public function authorize(): ?PaymentAuthorize
    {
        if (! $this->order) {
            $this->order = $this->cart?->draftOrder()->first()
                ?: $this->cart?->createOrder();
        }

        if (! $this->order) {
            return new PaymentAuthorize(
                success: false,
                message: 'Cannot authorize tpay payment without an order or cart',
                paymentType: (string) config('lunar-tpay.driver', 'tpay'),
            );
        }

        // Open an audit row before the API call so failures are visible too.
        $record = TpayTransaction::create([
            'order_id' => $this->order->id,
            'status' => TpayTransactionStatus::Pending,
            'amount' => (int) $this->order->total->value,
            'currency' => (string) ($this->order->currency_code ?: 'PLN'),
        ]);

        try {
            $response = $this->client->createTransaction(
                $this->buildTransactionPayload(),
            );
        } catch (Throwable $e) {
            $record->update([
                'status' => TpayTransactionStatus::CreateFailed,
                'last_event' => ['error' => $e->getMessage()],
            ]);
            TpayPaymentFailed::dispatch($this->order, $record->fresh(), $e->getMessage());

            $result = new PaymentAuthorize(
                success: false,
                message: 'Tpay createTransaction failed: '.$e->getMessage(),
                orderId: $this->order->id,
                paymentType: (string) config('lunar-tpay.driver', 'tpay'),
            );
            PaymentAttemptEvent::dispatch($result);

            return $result;
        }

        $tpayId = (string) ($response['transactionId'] ?? '');
        $redirectUrl = (string) ($response['transactionPaymentUrl'] ?? '');

        $record->update([
            'tpay_transaction_id' => $tpayId,
            'redirect_url' => $redirectUrl,
            'status' => TpayTransactionStatus::RedirectPending,
            'last_event' => ['create_response' => array_intersect_key($response, array_flip(['transactionId', 'status', 'result']))],
        ]);

        // Stamp the order with the latest tpay transaction id so the webhook can
        // find us by `hiddenDescription` (= order.id) or by stored tx id.
        $this->order->update([
            'meta' => array_merge((array) $this->order->meta, [
                'tpay' => [
                    'transaction_id' => $tpayId,
                    'redirect_url' => $redirectUrl,
                    'status' => $response['status'] ?? null,
                ],
            ]),
            'placed_at' => now(),
        ]);

        // Lunar PaymentAuthorize doesn't have a redirectUrl field; we surface it
        // through `message` so the storefront can read and forward the customer.
        $result = new PaymentAuthorize(
            success: $tpayId !== '' && $redirectUrl !== '',
            message: $redirectUrl,
            orderId: $this->order->id,
            paymentType: (string) config('lunar-tpay.driver', 'tpay'),
        );

        PaymentAttemptEvent::dispatch($result);

        return $result;
    }

    public function capture(TransactionContract $transaction, $amount = 0): PaymentCapture
    {
        // Tpay is webhook-driven — the final status comes from the notification.
        // Returning success here lets Lunar's checkout flow continue cleanly;
        // the webhook is what actually marks the order as paid.
        return new PaymentCapture(true);
    }

    public function refund(TransactionContract $transaction, int $amount = 0, $notes = null): PaymentRefund
    {
        // Refunds are intentionally not part of MVP — handle manually in tpay panel.
        return new PaymentRefund(false, 'Refunds are not supported by lunar-tpay yet — process via tpay merchant panel.');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTransactionPayload(): array
    {
        /** @var Order $order */
        $order = $this->order;
        $billing = $order->billingAddress;

        $email = (string) ($billing?->contact_email ?: $order->customer?->meta['email'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Tpay rejects invalid emails. Use a deterministic placeholder so that
            // the transaction can still be created when billing has no email yet
            // (e.g. guest checkout flows that collect email later).
            $email = sprintf('order-%d@no-email.local', $order->id);
        }

        $name = trim(((string) ($billing?->first_name ?? '')).' '.((string) ($billing?->last_name ?? '')));
        if ($name === '') {
            $name = 'Customer #'.$order->id;
        }

        $amount = round($order->total->value / 100, 2);

        $payload = [
            'amount' => $amount,
            'description' => $this->describeOrder($order),
            'hiddenDescription' => (string) $order->id,
            'lang' => 'pl',
            'payer' => [
                'email' => $email,
                'name' => $name,
            ],
            'callbacks' => [
                'payerUrls' => [
                    'success' => (string) config('lunar-tpay.return_url_success'),
                    'error' => (string) config('lunar-tpay.return_url_error'),
                ],
                'notification' => [
                    'url' => url((string) config('lunar-tpay.webhook_path', 'tpay/notify')),
                ],
            ],
        ];

        // Tpay rejects an empty `pay` object — only include it when the storefront
        // explicitly preselects a method via withData(['pay' => [...]]). Otherwise
        // tpay shows its own payment-method picker on the redirect page.
        if (! empty($this->data['pay'] ?? null)) {
            $payload['pay'] = $this->data['pay'];
        }

        return $payload;
    }

    private function describeOrder(Order $order): string
    {
        $count = $order->lines->count();

        return sprintf('Zamówienie #%d (%d %s)', $order->id, $count, $count === 1 ? 'pozycja' : 'pozycji');
    }
}
