<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Verifies tpay webhook notifications signed with JWS (RFC 7515).
 *
 * Tpay sends a detached JWS in the `X-JWS-Signature` header. Verification:
 *   1. Parse the header → `{header}.{empty}.{signature}` (compact, detached form).
 *   2. Decode the JOSE header — read `x5u` (URL to the signing certificate) or `x5c`.
 *   3. Fetch the signing cert (cached) and Tpay's root CA.
 *   4. Validate the signing cert chains up to the Tpay root.
 *   5. Recompute the signing input: `headerB64 . '.' . base64url(rawBody)`.
 *   6. Verify the signature using the cert's public key (RS256).
 *
 * Cert URLs:
 *   prod:    https://secure.tpay.com/x509/notifications-jws.pem
 *   sandbox: https://secure.sandbox.tpay.com/x509/notifications-jws.pem
 *
 * Root CA URLs:
 *   prod:    https://secure.tpay.com/x509/tpay-jws-root.pem
 *   sandbox: https://secure.sandbox.tpay.com/x509/tpay-jws-root.pem
 */
class TpayJwsVerifier
{
    private const PROD_HOST = 'https://secure.tpay.com';

    private const SANDBOX_HOST = 'https://secure.sandbox.tpay.com';

    private const SIGNING_CERT_PATH = '/x509/notifications-jws.pem';

    private const ROOT_CA_PATH = '/x509/tpay-jws-root.pem';

    public function __construct(
        private readonly bool $sandbox = true,
        private readonly int $certCacheSeconds = 86400,
    ) {}

    /**
     * Verify a detached JWS signature against the raw notification body.
     *
     * @param string $jwsHeader The raw value of the `X-JWS-Signature` header.
     * @param string $rawBody The unparsed request body (bytes — not the parsed array).
     */
    public function verify(string $jwsHeader, string $rawBody): bool
    {
        $parts = explode('.', $jwsHeader);
        if (count($parts) !== 3) {
            return false;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        // Detached JWS: middle section must be empty (payload is the raw body).
        if ($payloadB64 !== '') {
            return false;
        }

        $headerJson = self::base64UrlDecode($headerB64);
        $headerData = json_decode($headerJson, true);
        if (! is_array($headerData)) {
            return false;
        }

        $alg = (string) ($headerData['alg'] ?? '');
        if ($alg !== 'RS256') {
            // Only RS256 is in scope — tpay always uses it for notifications.
            return false;
        }

        $signingCertPem = $this->resolveSigningCert($headerData);
        if ($signingCertPem === null) {
            return false;
        }

        if (! $this->certIsTrusted($signingCertPem)) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($signingCertPem);
        if ($publicKey === false) {
            return false;
        }

        $signingInput = $headerB64.'.'.self::base64UrlEncode($rawBody);
        $signatureRaw = self::base64UrlDecode($signatureB64);

        $result = openssl_verify(
            $signingInput,
            $signatureRaw,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );

        return $result === 1;
    }

    /**
     * Resolve the signing certificate PEM from the JOSE header — preferring
     * inline `x5c` (cert chain), falling back to `x5u` (cert URL).
     *
     * @param array<string, mixed> $header
     */
    private function resolveSigningCert(array $header): ?string
    {
        if (isset($header['x5c'][0]) && is_string($header['x5c'][0])) {
            return self::derToPem(base64_decode($header['x5c'][0], true) ?: '');
        }

        if (isset($header['x5u']) && is_string($header['x5u'])) {
            // Tpay uses its own host — refuse arbitrary URLs to avoid SSRF.
            $expected = $this->host().self::SIGNING_CERT_PATH;
            if ($header['x5u'] !== $expected) {
                return null;
            }

            return $this->fetchPem('signing', $expected);
        }

        // No cert hint — fall back to the well-known signing cert URL.
        return $this->fetchPem('signing', $this->host().self::SIGNING_CERT_PATH);
    }

    private function certIsTrusted(string $signingCertPem): bool
    {
        $rootPem = $this->fetchPem('root', $this->host().self::ROOT_CA_PATH);

        // Write the root CA to a temp file so openssl_x509_checkpurpose can use it
        // as the trust anchor. Verify the signing cert chains up to it.
        $caFile = tempnam(sys_get_temp_dir(), 'tpay-ca-');
        if ($caFile === false) {
            return false;
        }

        try {
            file_put_contents($caFile, $rootPem);
            $result = openssl_x509_checkpurpose(
                $signingCertPem,
                X509_PURPOSE_ANY,
                [$caFile],
            );

            return $result === true;
        } finally {
            @unlink($caFile);
        }
    }

    private function fetchPem(string $kind, string $url): string
    {
        return Cache::remember(
            'lunar-tpay:cert:'.$kind.':'.md5($url),
            $this->certCacheSeconds,
            function () use ($url): string {
                $response = Http::timeout(15)->get($url);
                if ($response->failed()) {
                    throw new RuntimeException("Failed to fetch tpay cert from {$url}: HTTP {$response->status()}");
                }

                return $response->body();
            },
        );
    }

    private function host(): string
    {
        return $this->sandbox ? self::SANDBOX_HOST : self::PROD_HOST;
    }

    private static function base64UrlDecode(string $input): string
    {
        $padded = str_pad($input, strlen($input) + (4 - strlen($input) % 4) % 4, '=', STR_PAD_RIGHT);

        return base64_decode(strtr($padded, '-_', '+/'), true) ?: '';
    }

    private static function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private static function derToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".
            chunk_split(base64_encode($der), 64, "\n").
            "-----END CERTIFICATE-----\n";
    }
}
