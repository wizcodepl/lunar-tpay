<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use WizcodePl\LunarTpay\Actions\RecordTpayWebhookEvent;
use WizcodePl\LunarTpay\Actions\ResolveOrderFromNotification;
use WizcodePl\LunarTpay\Enums\TpayTransactionStatus;
use WizcodePl\LunarTpay\Jobs\ProcessTpayNotification;

/**
 * Receives tpay OpenAPI notifications.
 *
 * Authenticity is enforced upstream by the `VerifyTpayJws` middleware (RFC
 * 7515 detached JWS, RS256). By the time we run, the body is trusted.
 *
 * The controller is intentionally thin: resolve the Lunar Order, ack tpay
 * with the literal `TRUE`, and hand the heavy work (status update, audit
 * row, domain events) off to a queued job. Listeners doing slow work
 * (emails, fulfilment, PDFs) cannot starve the webhook past tpay's retry
 * window this way.
 */
class TpayWebhookController extends Controller
{
    public function __construct(
        private readonly ResolveOrderFromNotification $resolveOrder,
        private readonly RecordTpayWebhookEvent $recordEvent,
    ) {}

    public function __invoke(Request $request): Response
    {
        $body = $request->isJson() ? ($request->json()->all() ?? []) : $request->all();
        $payload = $this->extractPayload($body);

        Log::channel((string) config('lunar-tpay.log_channel', 'stack'))->info('lunar-tpay | notification received', [
            'transactionId' => $payload['transactionId'],
            'status' => $payload['status'],
            'amount' => $payload['amount'],
            'order_id' => $payload['order_id'],
        ]);

        $order = ($this->resolveOrder)($payload);
        if (! $order) {
            // Still record the event for forensics, but tell tpay we couldn't process.
            ($this->recordEvent)(null, $payload, TpayTransactionStatus::Failed, $body);

            return response('ORDER_NOT_FOUND', 404);
        }

        ProcessTpayNotification::dispatch($order, $payload, $body);

        return response('TRUE', 200);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{transactionId: string, status: string, amount: string, order_id: string}
     */
    private function extractPayload(array $body): array
    {
        return [
            'transactionId' => (string) ($body['transactionId'] ?? $body['tr_id'] ?? ''),
            'status' => (string) ($body['status'] ?? $body['tr_status'] ?? ''),
            'amount' => (string) ($body['amount'] ?? $body['tr_amount'] ?? ''),
            'order_id' => (string) ($body['hiddenDescription'] ?? $body['tr_crc'] ?? ''),
        ];
    }
}
