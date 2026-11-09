<?php

namespace Noria\Payments\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Noria\Payments\KcbBuniIpnVerifier;
use Symfony\Component\HttpFoundation\Response;

class VerifyKcbBuniIpn
{
    use ResolvesVerificationFlags;

    public function __construct(
        private readonly KcbBuniIpnVerifier $verifier,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string ...$flags): Response
    {
        [$enforceIpWhitelist, $verifySignature] = $this->resolveVerificationFlags($flags);

        if (! $this->verifier->verifyRequest($request, $enforceIpWhitelist, $verifySignature)) {
            abort(403, 'Invalid KCB Buni IPN.');
        }

        return $next($request);
    }
}
