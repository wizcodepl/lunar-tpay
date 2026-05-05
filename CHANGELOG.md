# Changelog

All notable changes to `wizcodepl/lunar-tpay` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **JWS x509 webhook signature verification** (`TpayJwsVerifier`) — RFC 7515 detached JWS, RS256, raw OpenSSL. Tpay signs notifications with its private key; we verify against the publicly-fetched Tpay signing cert chained up to the Tpay root CA. SSRF-guarded cert fetching (only `secure.tpay.com` / sandbox host accepted), 24h cert cache.

### Changed
- Webhook authentication is now **JWS-only**. Legacy md5sum verification removed.
- Removed config keys: `verification_mode`, `notification_secret`.

### Fixed
- `pay` payload key is now omitted entirely when no payment method is preselected via `withData(['pay' => …])`. Previously sent an empty `pay: {}`, which tpay rejected with `pay.groupId Is not neutral`.
- Empty payer email now falls back to `order-{id}@no-email.local` instead of an empty string (tpay rejected the latter as `payer.email Is not a valid email`).
- E2E sandbox test now asserts `result: 'success'` (the real OpenAPI response) instead of the documented but stale `'correct'`.

## [0.1.0] - 2026-05-04

### Added
- Tpay OpenAPI payment driver for Lunar PHP — registers as `tpay` in Lunar's `PaymentManager`.
- `TpayClient` over Laravel `Http` facade — OAuth2 client_credentials flow with token caching, `createTransaction`, `getTransaction`.
- `TpayPaymentDriver::authorize()` — creates Lunar Order if missing, calls `POST /transactions`, persists `transaction_id` + `redirect_url` on `Order.meta.tpay`, returns the redirect URL via `PaymentAuthorize.message`.
- `TpayWebhookController` — accepts both legacy form-encoded and JSON tpay notifications, verifies `md5sum` signature with `notification_secret`, maps `tr_status` to Lunar order status (`paid` / `cancelled` / `refunded`), responds with `TRUE` ACK.
- Configurable webhook path, sandbox/production toggle, return URLs.
- E2E test suite against the real tpay sandbox (no mocks) — `TpayClientTest`, `TpayPaymentDriverTest`, `TpayWebhookControllerTest`. Tests skip cleanly when `TPAY_CLIENT_ID` / `TPAY_NOTIFICATION_SECRET` aren't set, so CI without secrets stays green.

### Notes
- Refunds are intentionally out of scope — `refund()` returns `PaymentRefund(false, ...)`. Process via the tpay merchant panel.
- Retry queue is not part of v0.1; failed authorize attempts are dispatched as `PaymentAttemptEvent` and surface in `Order.meta.tpay`.
- Webhook signature verification uses tpay's legacy `md5sum` (still emitted in every notification). JWS x509 verification is on the roadmap.
