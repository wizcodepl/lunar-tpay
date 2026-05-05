<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use WizcodePl\LunarTpay\Tests\TestCase;
use WizcodePl\LunarTpay\TpayJwsVerifier;

/**
 * Verifies the JWS verifier *negative* paths (malformed input, wrong shape).
 *
 * The full positive path (real tpay-signed notification) needs a real sandbox
 * payment to fire its webhook against an internet-reachable endpoint — so that
 * scenario is documented in the README rather than tested here.
 */
#[Group('e2e')]
class TpayJwsVerifierTest extends TestCase
{
    private TpayJwsVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new TpayJwsVerifier(certBaseUrl: 'https://secure.sandbox.tpay.com');
    }

    public function test_returns_false_for_empty_signature(): void
    {
        $this->assertFalse($this->verifier->verify('', '{"foo":"bar"}'));
    }

    public function test_returns_false_for_signature_without_three_parts(): void
    {
        $this->assertFalse($this->verifier->verify('only-two.parts', '{"foo":"bar"}'));
    }

    public function test_returns_false_when_payload_section_is_not_empty(): void
    {
        // Detached JWS requires the middle section to be empty.
        $this->assertFalse($this->verifier->verify('header.something.signature', '{"foo":"bar"}'));
    }

    public function test_returns_false_for_unsupported_algorithm(): void
    {
        $headerB64 = self::base64UrlEncode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWS']));
        $signatureB64 = self::base64UrlEncode('not-a-real-signature');

        $this->assertFalse(
            $this->verifier->verify("{$headerB64}..{$signatureB64}", '{"foo":"bar"}'),
        );
    }

    public function test_returns_false_for_x5u_pointing_to_unknown_host(): void
    {
        // SSRF guard — only the well-known tpay cert URL is accepted.
        $headerB64 = self::base64UrlEncode((string) json_encode([
            'alg' => 'RS256',
            'typ' => 'JWS',
            'x5u' => 'https://attacker.example.com/cert.pem',
        ]));
        $signatureB64 = self::base64UrlEncode('xxx');

        $this->assertFalse(
            $this->verifier->verify("{$headerB64}..{$signatureB64}", '{"foo":"bar"}'),
        );
    }

    private static function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}
