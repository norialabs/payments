<?php

namespace Noria\Payments\Support;

use Noria\Payments\Contracts\AccessTokenProvider;

class StaticAccessTokenProvider implements AccessTokenProvider
{
    public function __construct(
        private readonly string $accessToken,
    ) {}

    public function getAccessToken(bool $forceRefresh = false): string
    {
        return $this->accessToken;
    }
}
