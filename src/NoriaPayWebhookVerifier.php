<?php

namespace Noria\Payments;

use Illuminate\Http\Request;
use Noria\Payments\Exceptions\ConfigurationException;

class NoriaPayWebhookVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {
        if ($secret === '') {
            throw new ConfigurationException('NoriaPayWebhookVerifier requires the endpoint signing secret.');
        }
    }

    public function verifyRequest(Request $request, ?int $now = null): bool
    {
        return $this->verify($request->getContent(), $request->headers->get('Pay-Signature'), $now);
    }

    public function verify(string $payload, ?string $signature, ?int $now = null): bool
    {
        $timestamp = null;
        $candidates = [];

        foreach (explode(',', (string) $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }
            if ($key === 'v1' && $value !== '') {
                $candidates[] = strtolower($value);
            }
        }

        if ($timestamp === null || $candidates === [] || abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret);

        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function sign(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret);
    }
}
