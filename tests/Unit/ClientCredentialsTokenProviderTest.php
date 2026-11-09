<?php

use Illuminate\Support\Facades\Http;
use Noria\Payments\Exceptions\AuthenticationException;
use Noria\Payments\Exceptions\ConfigurationException;
use Noria\Payments\Support\AccessToken;
use Noria\Payments\Support\ClientCredentialsTokenProvider;

it('caches tokens until expiry and force-refreshes on demand', function (): void {
    Http::fakeSequence('https://auth.example.test/token*')
        ->push([
            'access_token' => 'token-1',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'scope' => 'read',
        ], 200)
        ->push(['access_token' => 'token-2', 'expires_in' => 3600], 200)
        ->push(['access_token' => 'token-3', 'expires_in' => 3600], 200);

    $provider = new ClientCredentialsTokenProvider(
        http: Http::getFacadeRoot(),
        tokenUrl: 'https://auth.example.test/token',
        clientId: 'client',
        clientSecret: 'secret',
        timeoutSeconds: 5.0,
        query: ['grant_type' => 'client_credentials', 'ignored' => null],
        cacheSkewSeconds: 0,
    );

    $first = $provider->getToken();

    expect($first->accessToken)->toBe('token-1')
        ->and($first->tokenType)->toBe('Bearer')
        ->and($first->scope)->toBe('read')
        ->and($first->raw['access_token'])->toBe('token-1')
        ->and($provider->getAccessToken())->toBe('token-1')
        ->and($provider->getAccessToken(forceRefresh: true))->toBe('token-2');

    $provider->clearCache();

    expect($provider->getAccessToken())->toBe('token-3');

    Http::assertSentCount(3);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.example.test/token?grant_type=client_credentials'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('client:secret')));
});

it('throws AuthenticationException when the token endpoint returns a non-2xx status', function (): void {
    Http::fake([
        'https://auth-failed.example.test/token*' => Http::response(['error' => 'invalid'], 401),
    ]);

    expect(fn () => (new ClientCredentialsTokenProvider(
        Http::getFacadeRoot(),
        'https://auth-failed.example.test/token',
        'client',
        'secret',
    ))->getAccessToken())->toThrow(AuthenticationException::class, 'Authentication request failed.');
});

it('wraps connection timeouts as a typed AuthenticationException', function (): void {
    Http::fake([
        'https://auth-timeout.example.test/token*' => Http::failedConnection('operation timed out'),
    ]);

    expect(fn () => (new ClientCredentialsTokenProvider(
        Http::getFacadeRoot(),
        'https://auth-timeout.example.test/token',
        'client',
        'secret',
    ))->getAccessToken())->toThrow(AuthenticationException::class, 'Authentication request timed out.');
});

it('wraps general connection failures as a typed AuthenticationException', function (): void {
    Http::fake([
        'https://auth-connection.example.test/token*' => Http::failedConnection('connection refused'),
    ]);

    expect(fn () => (new ClientCredentialsTokenProvider(
        Http::getFacadeRoot(),
        'https://auth-connection.example.test/token',
        'client',
        'secret',
    ))->getAccessToken())->toThrow(AuthenticationException::class, 'Unable to obtain access token.');
});

it('lets callers map non-standard token responses with mapResponse', function (): void {
    Http::fake([
        'https://auth.example.test/custom*' => Http::response(['token' => 'mapped'], 200),
    ]);

    $mapped = new ClientCredentialsTokenProvider(
        http: Http::getFacadeRoot(),
        tokenUrl: 'https://auth.example.test/custom',
        clientId: 'client',
        clientSecret: 'secret',
        mapResponse: fn (array $payload): AccessToken => new AccessToken(
            accessToken: $payload['token'],
            expiresIn: 10,
            raw: $payload,
        ),
    );

    expect($mapped->getAccessToken())->toBe('mapped');
});

it('supports post form client-credentials token endpoints', function (): void {
    Http::fake([
        'https://auth.example.test/post-token' => Http::response(['access_token' => 'posted', 'expires_in' => 3600], 200),
    ]);

    $provider = new ClientCredentialsTokenProvider(
        http: Http::getFacadeRoot(),
        tokenUrl: 'https://auth.example.test/post-token',
        clientId: 'client',
        clientSecret: 'secret',
        method: 'POST',
        body: ['grant_type' => 'client_credentials'],
        asForm: true,
    );

    expect($provider->getAccessToken())->toBe('posted');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://auth.example.test/post-token'
        && $request['grant_type'] === 'client_credentials'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('client:secret')));
});

it('rejects empty credentials when built via forConfig', function (): void {
    expect(fn () => ClientCredentialsTokenProvider::forConfig(
        httpFactory: Http::getFacadeRoot(),
        tokenUrl: 'https://auth.example.test/token',
        config: ['consumer_key' => '', 'consumer_secret' => ''],
        idKey: 'consumer_key',
        secretKey: 'consumer_secret',
        missingCredentialsMessage: 'creds required',
    ))->toThrow(ConfigurationException::class, 'creds required');
});
