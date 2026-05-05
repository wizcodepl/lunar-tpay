<?php

declare(strict_types=1);

namespace WizcodePl\LunarTpay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use WizcodePl\LunarTpay\TpayJwsVerifier;

/**
 * Rejects any incoming webhook request that doesn't carry a valid
 * `X-JWS-Signature` matching the body. Controller downstream can assume
 * authenticity.
 */
class VerifyTpayJws
{
    public function __construct(
        private readonly TpayJwsVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $jwsHeader = (string) $request->header('X-JWS-Signature', '');

        if ($jwsHeader === '' || ! $this->verifier->verify($jwsHeader, $request->getContent())) {
            return response('SIGNATURE_INVALID', 403);
        }

        return $next($request);
    }
}
