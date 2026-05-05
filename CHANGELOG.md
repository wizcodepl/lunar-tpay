# Changelog

All notable changes to `wizcodepl/lunar-tpay` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-05-05

### Added
- **Status downgrade guard** in `UpdateOrderFromTpayStatus`. Once an order is `paid`, the only allowed next state is `refunded`. Replays of older `cancelled` / `false` notifications (legitimately late, or maliciously replayed) no longer flip the order back. Order's `meta.tpay.rejected_status` records the rejected payload for forensics.
- **Per-order locking** in `ProcessTpayNotification` via `Cache::lock` keyed by Lunar order id. Prevents two concurrent workers from double-dispatching domain events for notifications that arrive within milliseconds of each other.
- **DB transaction** wrapping the order update + audit-row write inside the job — partial failures roll back cleanly and the queue retry runs again under idempotency.

### Notes
- No breaking API change — all additions are internal hardening. Existing listeners and callers keep working.

## [1.0.0] - 2026-05-05

First stable release. Same feature set as 0.1.0 (nothing was added or removed); this tag declares the public API stable. From now on:

- Breaking changes to `TpayPaymentDriver`, `TpayWebhookController`, the four `Tpay*` events, `TpayTransaction` model, `TpayTransactionStatus` enum, the three `Actions/*` classes, the `VerifyTpayJws` middleware, the `ProcessTpayNotification` job, and the published config keys will require a major bump (2.0.0+).
- Internal refactors that don't change those signatures, plus new optional features, will go into 1.x.

The package is in production use as of this tag.

## [0.1.0] - 2026-05-05

Initial public release.

### Added
- **Tpay OpenAPI payment driver** for Lunar PHP — registers as `tpay` in Lunar's `PaymentManager`.
- `TpayClient` over Laravel `Http` facade — OAuth2 `client_credentials` flow with token caching, `createTransaction`, `getTransaction`.
- `TpayPaymentDriver::authorize()` — creates Lunar Order if missing, calls `POST /transactions`, persists `transaction_id` + `redirect_url` on `Order.meta.tpay`, returns the redirect URL via `PaymentAuthorize.message` (Lunar has no dedicated `redirectUrl` field).
- **JWS x509 webhook signature verification** (`TpayJwsVerifier`) — RFC 7515 detached JWS, RS256, raw OpenSSL. Tpay signs notifications with its private key; we verify against the publicly-fetched Tpay signing cert chained up to the Tpay root CA. SSRF-guarded cert fetching (only `secure.tpay.com` / sandbox host accepted), 24h cert cache.
- **`VerifyTpayJws` middleware** — JWS verification extracted from the controller; rejects unsigned / invalid bodies with `403 SIGNATURE_INVALID` before downstream code runs.
- **`tpay_transactions` audit table** — append-only log of every authorize attempt and webhook event (`tpay_transaction_id`, status, amount, redirect URL, last raw event, last notification timestamp). Indexed by `tpay_transaction_id` and `status` for fast lookups.
- **`TpayTransactionStatus` enum** — `Pending` / `CreateFailed` / `RedirectPending` / `Paid` / `Failed` / `Refunded` / `Cancelled` — used as the model cast on `TpayTransaction.status`.
- **Domain events** — `TpayPaymentReceived`, `TpayPaymentFailed`, `TpayPaymentRefunded`, `TpayPaymentCancelled`. Listeners can implement `ShouldQueue` to do slow work (mails, fulfilment) without starving the webhook.
- **Actions decomposition** — webhook handling split into `ResolveOrderFromNotification`, `UpdateOrderFromTpayStatus`, `RecordTpayWebhookEvent`. Controller composes them; each is independently testable.
- **`ProcessTpayNotification` job** — webhook controller acks tpay with `TRUE` immediately and dispatches the job, so heavy listener work happens on a queue worker (configurable per Laravel queue connection).
- **Idempotency** — if a notification arrives for a transaction already in the resolved terminal status (`Paid` / `Refunded` / `Cancelled` / `Failed`), domain events are not re-dispatched. Tpay can safely retry.
- **Amount sanity check** — on `paid` notifications the reported amount is compared against the order total; mismatch downgrades the transaction to `Failed` and logs a warning. Defense in depth on top of JWS.
- Currency on the audit row + on the `POST /transactions` payload is read from `$order->currency_code`, not hardcoded PLN.
- Configurable webhook path, sandbox / production toggle, return URLs (`return_url_success`, `return_url_error`), log channel.
- E2E test suite against the real tpay sandbox (no mocks) — `TpayClientTest`, `TpayPaymentDriverTest`, `TpayWebhookControllerTest`, `ProcessTpayNotificationTest`. Tests skip cleanly when `TPAY_CLIENT_ID` / `TPAY_CLIENT_SECRET` aren't set, so CI without secrets stays green.
- CI workflows for tests / pint / phpstan, matrix PHP 8.2 / 8.3 / 8.4 against Lunar `^1.3`.

### Notes
- **Refunds are out of scope.** `refund()` returns `PaymentRefund(false, …)`. Process refunds via the tpay merchant panel.
- **Retries** are not part of v0.1. Failed authorize attempts surface as `PaymentAttemptEvent` and as a `TpayTransaction` row in `create_failed` status.
- Webhook authentication is **JWS-only**. Legacy `md5sum` verification is intentionally not supported.
