<?php

namespace NoriaLabs\Payments\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Exceptions\AuthenticationException;
use NoriaLabs\Payments\Exceptions\ConfigurationException;

class ClientCredentialsTokenProvider implements AccessTokenProvider
{
    private ?AccessToken $cachedToken = null;

    private int $expiresAt = 0;

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, scalar|null>  $body
     * @param  callable(array<string, mixed>): AccessToken|null  $mapResponse
     */
    public function __construct(
        private readonly Factory $http,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?float $timeoutSeconds = null,
        private readonly array $query = [],
        private readonly int $cacheSkewSeconds = 60,
        private readonly mixed $mapResponse = null,
        private readonly string $method = 'GET',
        private readonly array $body = [],
        private readonly bool $asForm = false,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function forConfig(
        Factory $httpFactory,
        string $tokenUrl,
        array $config,
        string $idKey,
        string $secretKey,
        string $missingCredentialsMessage,
        string $cacheSkewKey = 'token_cache_skew_seconds',
        ?CacheFactory $cacheFactory = null,
        ?string $cacheKey = null,
    ): AccessTokenProvider {
        $clientId = $config[$idKey] ?? null;
        $clientSecret = $config[$secretKey] ?? null;

        if (empty($clientId) || empty($clientSecret)) {
            throw new ConfigurationException($missingCredentialsMessage);
        }

        $skew = Setting::int($config[$cacheSkewKey] ?? $config['token_cache_skew_seconds'] ?? null, 60);

        $provider = new self(
            http: $httpFactory,
            tokenUrl: $tokenUrl,
            clientId: Setting::string($clientId),
            clientSecret: Setting::string($clientSecret),
            timeoutSeconds: Setting::float($config['timeout_seconds'] ?? null),
            query: ['grant_type' => 'client_credentials'],
            cacheSkewSeconds: $skew,
        );

        $cacheStore = $config['cache_store'] ?? null;

        if ($cacheFactory === null || $cacheStore === null || $cacheStore === false || $cacheKey === null) {
            return $provider;
        }

        $repository = $cacheStore === true || $cacheStore === '' || $cacheStore === 'default'
            ? $cacheFactory->store()
            : $cacheFactory->store(Setting::string($cacheStore));

        return new CachedAccessTokenProvider(
            inner: $provider,
            cache: $repository,
            cacheKey: $cacheKey,
            cacheSkewSeconds: $skew,
            cacheTtlSeconds: Setting::int($config['cache_ttl_seconds'] ?? 0) ?: null,
        );
    }

    public function getAccessToken(bool $forceRefresh = false): string
    {
        return $this->getToken($forceRefresh)->accessToken;
    }

    public function clearCache(): void
    {
        $this->cachedToken = null;
        $this->expiresAt = 0;
    }

    public function getToken(bool $forceRefresh = false): AccessToken
    {
        if (! $forceRefresh && $this->cachedToken !== null && time() < $this->expiresAt) {
            return $this->cachedToken;
        }

        try {
            $request = $this->http
                ->withHeaders([
                    'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
                    'Accept' => 'application/json',
                ]);

            if ($this->timeoutSeconds !== null) {
                $request = $request->timeout($this->timeoutSeconds);
            }

            $query = array_filter($this->query, static fn ($value) => $value !== null);

            if (strtoupper($this->method) === 'GET') {
                $response = $request->get($this->tokenUrl, $query);
            } else {
                if ($this->asForm) {
                    $request = $request->asForm();
                }

                $response = $request->send(strtoupper($this->method), $this->tokenUrl, array_filter([
                    'query' => $query,
                    $this->asForm ? 'form_params' : 'json' => array_filter($this->body, static fn ($value) => $value !== null),
                ], static fn ($value) => $value !== []));
            }
        } catch (ConnectionException $exception) {
            $message = str_contains(strtolower($exception->getMessage()), 'timed out')
                ? 'Authentication request timed out.'
                : 'Unable to obtain access token.';

            throw new AuthenticationException($message, ['exception' => $exception]);
        }

        $payload = $response->json() ?: [];

        if (! $response->successful()) {
            throw new AuthenticationException('Authentication request failed.', $payload);
        }

        $mapper = $this->mapResponse ?? function (array $input): AccessToken {
            $input = Setting::map($input);

            return new AccessToken(
                accessToken: Setting::string($input['access_token'] ?? null),
                expiresIn: Setting::int($input['expires_in'] ?? null),
                tokenType: isset($input['token_type']) ? Setting::string($input['token_type']) : null,
                scope: isset($input['scope']) ? Setting::string($input['scope']) : null,
                raw: $input,
            );
        };

        $token = $mapper(Setting::map($payload));
        $this->cachedToken = $token;
        $this->expiresAt = time() + max(0, $token->expiresIn - $this->cacheSkewSeconds);

        return $token;
    }
}
