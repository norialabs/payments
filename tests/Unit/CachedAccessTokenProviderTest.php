<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Noria\Payments\Contracts\AccessTokenProvider;
use Noria\Payments\Support\CachedAccessTokenProvider;
use Noria\Payments\Support\ClientCredentialsTokenProvider;

function arrayCacheRepository(): Repository
{
    return new Repository(new ArrayStore);
}

function arrayCacheFactory(): CacheManager
{
    $app = new Application;
    $app['config'] = new Illuminate\Config\Repository([
        'cache' => [
            'default' => 'array',
            'stores' => [
                'array' => ['driver' => 'array'],
            ],
            'prefix' => '',
        ],
    ]);

    return new CacheManager($app);
}

it('shares a token across instances via the laravel cache', function (): void {
    $tokens = ['cached-token', 'should-not-be-fetched', 'after-clear'];
    $served = [];

    Http::fake(function () use (&$tokens, &$served) {
        $served[] = $token = array_shift($tokens) ?? 'exhausted';

        return Http::response(['access_token' => $token, 'expires_in' => 600], 200);
    });

    $cache = arrayCacheRepository();

    $provider = new CachedAccessTokenProvider(
        inner: new ClientCredentialsTokenProvider(
            http: Http::getFacadeRoot(),
            tokenUrl: 'https://auth.example.test/token',
            clientId: 'client',
            clientSecret: 'secret',
        ),
        cache: $cache,
        cacheKey: 'payments:test:token',
        cacheSkewSeconds: 30,
    );

    expect($provider->getAccessToken())->toBe('cached-token')
        ->and($provider->getAccessToken())->toBe('cached-token')
        ->and($provider->getAccessToken(forceRefresh: true))->toBe('should-not-be-fetched')
        ->and($served)->toBe(['cached-token', 'should-not-be-fetched']);

    $provider->clearCache();

    expect($provider->getAccessToken())->toBe('after-clear')
        ->and($served)->toBe(['cached-token', 'should-not-be-fetched', 'after-clear']);
});

it('caches a generic AccessTokenProvider by writing the returned string', function (): void {
    $calls = 0;

    $inner = new class($calls) implements AccessTokenProvider
    {
        public function __construct(public int &$calls) {}

        public function getAccessToken(bool $forceRefresh = false): string
        {
            $this->calls++;

            return $forceRefresh ? 'refreshed' : 'first';
        }
    };

    $cache = arrayCacheRepository();

    $provider = new CachedAccessTokenProvider(
        inner: $inner,
        cache: $cache,
        cacheKey: 'payments:test:generic-token',
        cacheTtlSeconds: 120,
    );

    expect($provider->getAccessToken())->toBe('first')
        ->and($provider->getAccessToken())->toBe('first')
        ->and($calls)->toBe(1)
        ->and($provider->getAccessToken(forceRefresh: true))->toBe('refreshed')
        ->and($calls)->toBe(2);

    $provider->clearCache();

    expect($cache->has('payments:test:generic-token'))->toBeFalse();
});

it('does not write to cache when the resolved ttl is zero or negative', function (): void {
    Http::fake([
        'https://auth.example.test/zero*' => Http::response(['access_token' => 'no-ttl', 'expires_in' => 0], 200),
    ]);

    $cache = arrayCacheRepository();

    $provider = new CachedAccessTokenProvider(
        inner: new ClientCredentialsTokenProvider(
            http: Http::getFacadeRoot(),
            tokenUrl: 'https://auth.example.test/zero',
            clientId: 'client',
            clientSecret: 'secret',
        ),
        cache: $cache,
        cacheKey: 'payments:test:zero-ttl',
        cacheSkewSeconds: 60,
    );

    expect($provider->getAccessToken())->toBe('no-ttl')
        ->and($cache->has('payments:test:zero-ttl'))->toBeFalse();
});

it('does not cache a generic AccessTokenProvider when no ttl is configured', function (): void {
    $calls = 0;

    $inner = new class($calls) implements AccessTokenProvider
    {
        public function __construct(public int &$calls) {}

        public function getAccessToken(bool $forceRefresh = false): string
        {
            $this->calls++;

            return 'generic-'.$this->calls;
        }
    };

    $cache = arrayCacheRepository();

    $provider = new CachedAccessTokenProvider(
        inner: $inner,
        cache: $cache,
        cacheKey: 'payments:test:generic-no-ttl',
    );

    expect($provider->getAccessToken())->toBe('generic-1')
        ->and($provider->getAccessToken())->toBe('generic-2')
        ->and($cache->has('payments:test:generic-no-ttl'))->toBeFalse();
});

it('produces a cache-decorated provider when forConfig is given a cache_store', function (): void {
    Http::fake([
        'https://auth.example.test/factory*' => Http::response(['access_token' => 'factory-token', 'expires_in' => 600], 200),
    ]);

    $factory = arrayCacheFactory();

    $provider = ClientCredentialsTokenProvider::forConfig(
        httpFactory: Http::getFacadeRoot(),
        tokenUrl: 'https://auth.example.test/factory',
        config: [
            'consumer_key' => 'client',
            'consumer_secret' => 'secret',
            'cache_store' => 'array',
            'cache_ttl_seconds' => 600,
        ],
        idKey: 'consumer_key',
        secretKey: 'consumer_secret',
        missingCredentialsMessage: 'creds required',
        cacheFactory: $factory,
        cacheKey: 'payments:test:factory-token',
    );

    expect($provider)->toBeInstanceOf(CachedAccessTokenProvider::class)
        ->and($provider->getAccessToken())->toBe('factory-token')
        ->and($factory->store('array')->get('payments:test:factory-token'))->toBe('factory-token');
});

it('returns the bare provider when forConfig has no cache configured', function (): void {
    $provider = ClientCredentialsTokenProvider::forConfig(
        httpFactory: Http::getFacadeRoot(),
        tokenUrl: 'https://auth.example.test/no-cache',
        config: [
            'consumer_key' => 'client',
            'consumer_secret' => 'secret',
        ],
        idKey: 'consumer_key',
        secretKey: 'consumer_secret',
        missingCredentialsMessage: 'creds required',
        cacheFactory: arrayCacheFactory(),
        cacheKey: 'payments:test:no-cache',
    );

    expect($provider)->toBeInstanceOf(ClientCredentialsTokenProvider::class);
});

it('uses the default cache store when cache_store is true', function (): void {
    Http::fake([
        'https://auth.example.test/default*' => Http::response(['access_token' => 'default-store', 'expires_in' => 600], 200),
    ]);

    $factory = arrayCacheFactory();

    $provider = ClientCredentialsTokenProvider::forConfig(
        httpFactory: Http::getFacadeRoot(),
        tokenUrl: 'https://auth.example.test/default',
        config: [
            'consumer_key' => 'client',
            'consumer_secret' => 'secret',
            'cache_store' => true,
        ],
        idKey: 'consumer_key',
        secretKey: 'consumer_secret',
        missingCredentialsMessage: 'creds required',
        cacheFactory: $factory,
        cacheKey: 'payments:test:default-store',
    );

    expect($provider)->toBeInstanceOf(CachedAccessTokenProvider::class)
        ->and($provider->getAccessToken())->toBe('default-store');
});
