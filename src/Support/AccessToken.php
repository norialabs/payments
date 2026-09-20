<?php

namespace NoriaLabs\Payments\Support;

class AccessToken
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly int $expiresIn,
        public readonly ?string $tokenType = null,
        public readonly ?string $scope = null,
        public readonly array $raw = [],
    ) {}
}
