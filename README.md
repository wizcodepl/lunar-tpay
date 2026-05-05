<p align="center">
  <img src="art/logo.svg" alt="Lunar Tpay" width="200">
</p>

# lunar-tpay

[![tests](https://github.com/wizcodepl/lunar-tpay/actions/workflows/tests.yml/badge.svg)](https://github.com/wizcodepl/lunar-tpay/actions/workflows/tests.yml)
[![pint](https://github.com/wizcodepl/lunar-tpay/actions/workflows/pint.yml/badge.svg)](https://github.com/wizcodepl/lunar-tpay/actions/workflows/pint.yml)
[![phpstan](https://github.com/wizcodepl/lunar-tpay/actions/workflows/phpstan.yml/badge.svg)](https://github.com/wizcodepl/lunar-tpay/actions/workflows/phpstan.yml)
[![packagist](https://img.shields.io/packagist/v/wizcodepl/lunar-tpay.svg)](https://packagist.org/packages/wizcodepl/lunar-tpay)
[![license](https://img.shields.io/packagist/l/wizcodepl/lunar-tpay.svg)](LICENSE)

[Tpay](https://tpay.com/) (OpenAPI) payment driver for [Lunar PHP](https://lunarphp.com/).

Authorize → redirect customer to tpay → JWS-verified webhook updates the order.

## Installation

```bash
composer require wizcodepl/lunar-tpay
php artisan vendor:publish --tag=lunar-tpay-config
```

```env
TPAY_CLIENT_ID=...
TPAY_CLIENT_SECRET=...
TPAY_SANDBOX=true
TPAY_RETURN_URL_SUCCESS=https://your-shop.com/order/ok
TPAY_RETURN_URL_ERROR=https://your-shop.com/order/error
```

Get sandbox credentials at [register.sandbox.tpay.com](https://register.sandbox.tpay.com/) and
generate Open API keys in **Merchant Panel → Integration → API**.

In your Lunar `config/lunar.php`, register the driver:

```php
'payments' => [
    'types' => [
        'tpay' => ['driver' => 'tpay'],
    ],
],
```

The webhook route `POST /tpay/notify` is registered automatically. Set this URL in your tpay merchant panel.

## Usage

```php
$result = Payments::driver('tpay')
    ->cart($cart)
    ->authorize();

if ($result->success) {
    return redirect()->away($result->message);  // tpay redirect URL
}
```

The `PaymentAuthorize.message` field carries the redirect URL — that's a Lunar API quirk (the DTO doesn't have a dedicated `redirectUrl` field).

You can preselect a payment method (BLIK, card, specific bank) via `withData()`:

```php
Payments::driver('tpay')
    ->cart($cart)
    ->withData(['pay' => ['groupId' => 150]])  // 150 = BLIK
    ->authorize();
```

When omitted, tpay shows its own payment-method picker on the redirect page.

## Webhook authentication — JWS x509

Tpay signs every notification with its private key (RFC 7515 detached JWS, RS256).
We verify against the publicly-fetched Tpay signing certificate, chained up to
the Tpay root CA. Both certificates live at:

| | Production | Sandbox |
|---|---|---|
| Signing cert | `https://secure.tpay.com/x509/notifications-jws.pem` | `https://secure.sandbox.tpay.com/x509/notifications-jws.pem` |
| Root CA | `https://secure.tpay.com/x509/tpay-jws-root.pem` | `https://secure.sandbox.tpay.com/x509/tpay-jws-root.pem` |

Certs are fetched once and cached for 24h. Notifications without a valid
`X-JWS-Signature` header are rejected with `403 SIGNATURE_INVALID`. Legacy
md5sum verification is intentionally not supported — JWS is the modern,
asymmetric path with no shared secret to leak.

## Testing

This package uses **real sandbox tests** rather than mocks. The philosophy
mirrors how Stripe, PayPal, and AWS PHP SDKs do it: real OAuth, real `POST
/transactions`, real `GET /transactions/{id}` against the sandbox.

### Run locally

```bash
cd packages/lunar-tpay
composer install

export TPAY_CLIENT_ID="your-sandbox-client-id"
export TPAY_CLIENT_SECRET="your-sandbox-secret"

composer test         # unit + sandbox e2e
composer format       # Pint auto-fix
composer format:check # Pint check (CI mode)
composer analyse      # PHPStan level 5
```

Without these env vars the e2e sandbox tests skip cleanly with a clear message
— CI without secrets stays green.

### What's covered

| Test | Tests |
|---|---|
| `TpayClientTest` | sandbox OAuth + create/get transaction round-trip + error handling |
| `TpayPaymentDriverTest` | driver registration in Lunar PaymentManager + sandbox-backed `authorize()` flow + meta persistence |
| `TpayJwsVerifierTest` | JWS verifier negative paths (empty/malformed/wrong-alg/SSRF guard) |
| `TpayWebhookControllerTest` | controller logic (status mapping, order lookup, response codes) — verifier swapped with a deterministic stub via the container so we don't need a real tpay signature for controller-level tests |

The full positive-path JWS verification needs a real tpay-signed payload,
which only happens after a real sandbox transaction fires its webhook against
a publicly-reachable URL. Validate that scenario manually after deploying
to a staging URL.

### Why no `MockClient` / VCR cassettes

For payment flows the temptation is to record API responses (à la
[VCR](https://github.com/vcr-php/vcr)) and replay them. We chose against this
because:

- Sandbox is **free and reliable**. tpay's OpenAPI sandbox is stable.
- Recorded fixtures **drift silently** when the API changes — and payment
  schemas change more often than people expect.
- Real sandbox calls catch credential / cert / DNS issues that mocks can't.

The downside is that running tests requires creds — solved by skip-on-empty.

## What's intentionally not in v1.0

- **Refunds** — `refund()` returns `PaymentRefund(false, …)`. Use the tpay
  merchant panel for now.
- **Retry queue** — single attempt; failure is logged and dispatched as a
  Lunar `PaymentAttemptEvent`. Wrap with your own retry policy if needed.
- **Filament admin UI** — `Order.meta.tpay` is the source of truth; surface
  it in your panel however you prefer.

## License

MIT — see [LICENSE](LICENSE).
