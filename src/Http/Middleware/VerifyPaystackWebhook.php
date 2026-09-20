<?php

namespace NoriaLabs\Payments\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NoriaLabs\Payments\PaystackWebhookVerifier;
use Symfony\Component\HttpFoundation\Response;

class VerifyPaystackWebhook
{
    use ResolvesVerificationFlags;

    public function __construct(
        private readonly PaystackWebhookVerifier $verifier,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string ...$flags): Response
    {
        [$enforceIpWhitelist, $verifySignature] = $this->resolveVerificationFlags($flags);

        if (! $this->verifier->verifyRequest($request, $enforceIpWhitelist, $verifySignature)) {
            abort(403, 'Invalid Paystack webhook.');
        }

        return $next($request);
    }
}
