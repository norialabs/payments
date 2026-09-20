<?php

namespace NoriaLabs\Payments\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NoriaLabs\Payments\SasaPayCallbackVerifier;
use Symfony\Component\HttpFoundation\Response;

class VerifySasaPayCallback
{
    use ResolvesVerificationFlags;

    public function __construct(
        private readonly SasaPayCallbackVerifier $verifier,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string ...$flags): Response
    {
        [$enforceIpWhitelist, $verifySignature] = $this->resolveVerificationFlags($flags);

        if (! $this->verifier->verifyRequest($request, $enforceIpWhitelist, $verifySignature)) {
            abort(403, 'Invalid SasaPay callback.');
        }

        return $next($request);
    }
}
