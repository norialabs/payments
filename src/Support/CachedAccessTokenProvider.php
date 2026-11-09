<?php

namespace Noria\Payments\Support;

use Illuminate\Contracts\Cache\Repository;
use Noria\Payments\Contracts\AccessTokenProvider;

class CachedAccessTokenProvider implements AccessTokenProvider
{
    public function __construct(
        private readonly AccessTokenProvider $inner,
        private readonly Repository $cache,
        private readonly string $cacheKey,
        private readonly int $cacheSkewSeconds = 60,
        private readonly ?int $cacheTtlSeconds = null,
    ) {}

    public function getAccessToken(bool $forceRefresh = false): string
    {
        if (! $forceRefresh) {
            $cached = $this->cache->get($this->cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        if ($this->inner instanceof ClientCredentialsTokenProvider) {
            $token = $this->inner->getToken($forceRefresh);
            $ttl = $this->resolveTtl($token->expiresIn);

            if ($ttl > 0) {
                $this->cache->put($this->cacheKey, $token->accessToken, $ttl);
            }

            return $token->accessToken;
        }

        $accessToken = $this->inner->getAccessToken($forceRefresh);
        $ttl = $this->resolveTtl(null);

        if ($ttl > 0) {
            $this->cache->put($this->cacheKey, $accessToken, $ttl);
        }

        return $accessToken;
    }

    public function clearCache(): void
    {
        $this->cache->forget($this->cacheKey);

        if ($this->inner instanceof ClientCredentialsTokenProvider) {
            $this->inner->clearCache();
        }
    }

    private function resolveTtl(?int $expiresIn): int
    {
        if ($this->cacheTtlSeconds !== null) {
            return max(0, $this->cacheTtlSeconds - $this->cacheSkewSeconds);
        }

        if ($expiresIn === null) {
            return 0;
        }

        return max(0, $expiresIn - $this->cacheSkewSeconds);
    }
}
