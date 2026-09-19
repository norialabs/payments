<?php

namespace NoriaLabs\Payments\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use NoriaLabs\Payments\Exceptions\ApiException;
use NoriaLabs\Payments\Exceptions\NetworkException;
use NoriaLabs\Payments\Exceptions\TimeoutException;
use SplFileInfo;

class HttpTransport
{
    /**
     * @param  array<string, string>  $defaultHeaders
     */
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly ?float $timeoutSeconds = null,
        private readonly array $defaultHeaders = [],
        private readonly ?RetryPolicy $retry = null,
        private readonly ?Hooks $hooks = null,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, scalar|null>|null  $query
     * @param  array<mixed>|null  $multipart
     */
    public function send(
        string $path,
        string $method = 'GET',
        array $headers = [],
        ?array $query = null,
        mixed $body = null,
        ?float $timeoutSeconds = null,
        RetryPolicy|false|null $retry = null,
        ?array $multipart = null,
    ): mixed {
        $url = $this->appendPath($path);
        $resolvedRetry = $this->resolveRetryPolicy($retry);
        $maxAttempts = $resolvedRetry->maxAttempts ?? 1;
        $resolvedTimeout = $timeoutSeconds ?? $this->timeoutSeconds;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $mergedHeaders = array_merge($this->defaultHeaders, $headers);

            if ($multipart !== null && ! $this->hasHeader($headers, 'Content-Type')) {
                $mergedHeaders = $this->withoutHeader($mergedHeaders, 'Content-Type');
            }

            $requestBody = $multipart ?? $body;
            $context = new BeforeRequestContext(
                url: $url,
                path: $path,
                method: strtoupper($method),
                headers: $mergedHeaders,
                body: $requestBody,
                attempt: $attempt,
            );

            foreach ($this->hooks?->beforeRequestCallbacks() ?? [] as $callback) {
                $callback($context);
            }

            try {
                $response = $this->performRequest(
                    method: $method,
                    url: $url,
                    headers: $context->headers,
                    query: $query,
                    body: $multipart === null ? $context->body : null,
                    multipart: $multipart === null ? null : $context->body,
                    timeoutSeconds: $resolvedTimeout,
                );
            } catch (ConnectionException $exception) {
                $wrapped = str_contains(strtolower($exception->getMessage()), 'timed out')
                    ? new TimeoutException("Request timed out for {$url}", ['exception' => $exception])
                    : new NetworkException("Request failed for {$url}", ['exception' => $exception]);

                $this->dispatchErrorHooks($context, $wrapped);

                $decision = new RetryDecisionContext(
                    attempt: $attempt,
                    maxAttempts: $maxAttempts,
                    method: strtoupper($method),
                    url: $url,
                    error: $wrapped,
                );

                if ($this->shouldRetry($resolvedRetry, $decision)) {
                    $this->sleepBeforeRetry($resolvedRetry, $attempt, null);

                    continue;
                }

                throw $wrapped;
            }

            $responseBody = $this->parseResponseBody($response);

            foreach ($this->hooks?->afterResponseCallbacks() ?? [] as $callback) {
                $callback(new AfterResponseContext(
                    url: $context->url,
                    path: $context->path,
                    method: $context->method,
                    headers: $context->headers,
                    body: $context->body,
                    attempt: $context->attempt,
                    response: $response,
                    responseBody: $responseBody,
                ));
            }

            if ($response->successful()) {
                return $responseBody;
            }

            $exception = new ApiException(
                message: $this->buildErrorMessage($response->status(), $responseBody),
                statusCode: $response->status(),
                responseBody: $responseBody,
            );

            $this->dispatchErrorHooks($context, $exception, $response, $responseBody);

            $decision = new RetryDecisionContext(
                attempt: $attempt,
                maxAttempts: $maxAttempts,
                method: strtoupper($method),
                url: $url,
                status: $response->status(),
                error: $exception,
                response: $response,
                responseBody: $responseBody,
            );

            if ($this->shouldRetry($resolvedRetry, $decision)) {
                $this->sleepBeforeRetry($resolvedRetry, $attempt, $response);

                continue;
            }

            throw $exception;
        }

        throw new NetworkException('Unreachable retry state.');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string|int, mixed>  $files
     * @param  array<string, string>  $headers
     * @param  array<string, scalar|null>|null  $query
     */
    public function sendMultipart(
        string $path,
        string $method = 'POST',
        array $fields = [],
        array $files = [],
        array $headers = [],
        ?array $query = null,
        ?float $timeoutSeconds = null,
        RetryPolicy|false|null $retry = null,
    ): mixed {
        return $this->send(
            path: $path,
            method: $method,
            headers: $headers,
            query: $query,
            timeoutSeconds: $timeoutSeconds,
            retry: $retry,
            multipart: $this->multipartParts($fields, $files),
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, scalar|null>|null  $query
     * @param  array<mixed>|null  $multipart
     */
    private function performRequest(
        string $method,
        string $url,
        array $headers,
        ?array $query,
        mixed $body,
        ?array $multipart,
        ?float $timeoutSeconds,
    ): Response {
        $request = $this->http->withHeaders($headers);

        if ($timeoutSeconds !== null) {
            $request = $request->timeout($timeoutSeconds);
        }

        $query = array_filter($query ?? [], static fn ($value) => $value !== null);

        if ($body === null) {
            if ($multipart !== null) {
                return $request
                    ->asMultipart()
                    ->send($method, $url, ['query' => $query, 'multipart' => $this->normalizeMultipart($multipart)]);
            }

            return $request->send($method, $url, ['query' => $query]);
        }

        if (is_string($body)) {
            if (! $this->hasHeader($headers, 'Content-Type')) {
                $request = $request->withHeaders(['Content-Type' => 'text/plain;charset=UTF-8']);
            }

            return $request->send($method, $url, ['query' => $query, 'body' => $body]);
        }

        if (! $this->hasHeader($headers, 'Content-Type')) {
            $request = $request->withHeaders(['Content-Type' => 'application/json']);
        }

        if ($body === []) {
            $body = new \stdClass;
        }

        return $request->send($method, $url, ['query' => $query, 'json' => $body]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string|int, mixed>  $files
     * @return array<int, array<string, mixed>>
     */
    private function multipartParts(array $fields, array $files): array
    {
        $parts = [];

        foreach ($fields as $name => $value) {
            $this->appendMultipartValue($parts, (string) $name, $value, false);
        }

        foreach ($files as $name => $file) {
            if (is_int($name) && is_array($file) && (isset($file['name']) || isset($file['contents']) || isset($file['path']))) {
                $parts[] = $this->normalizeMultipartPart($file);

                continue;
            }

            $parts[] = $this->normalizeFilePart((string) $name, $file);
        }

        return $parts;
    }

    /**
     * @param  array<mixed>  $multipart
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMultipart(array $multipart): array
    {
        if (array_is_list($multipart)) {
            return array_map(fn (mixed $part): array => $this->normalizeMultipartPart($part), $multipart);
        }

        $parts = [];

        foreach ($multipart as $name => $value) {
            $this->appendMultipartValue($parts, (string) $name, $value, true);
        }

        return $parts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $parts
     */
    private function appendMultipartValue(array &$parts, string $name, mixed $value, bool $treatFilePathsAsFiles): void
    {
        if ($this->isFileInput($value, $treatFilePathsAsFiles)) {
            $parts[] = $this->normalizeFilePart($name, $value);

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $nestedName = is_int($key) ? "{$name}[]" : "{$name}[{$key}]";
                $this->appendMultipartValue($parts, $nestedName, $item, $treatFilePathsAsFiles);
            }

            return;
        }

        $parts[] = [
            'name' => $name,
            'contents' => $value ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMultipartPart(mixed $part): array
    {
        if (! is_array($part)) {
            return [
                'name' => 'file',
                'contents' => $this->rewindIfResource($part),
            ];
        }

        $name = (string) ($part['name'] ?? 'file');

        if (isset($part['path']) && is_string($part['path']) && $part['path'] !== '') {
            $filePart = $this->normalizePathFilePart($name, $part['path'], $part['filename'] ?? null);
            $filePart['headers'] = $part['headers'] ?? [];

            return $filePart;
        }

        return array_filter([
            'name' => $name,
            'contents' => $this->rewindIfResource($part['contents'] ?? ''),
            'filename' => $part['filename'] ?? null,
            'headers' => $part['headers'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFilePart(string $name, mixed $file): array
    {
        if ($file instanceof UploadedFile) {
            return $this->normalizePathFilePart($name, $file->getRealPath() ?: $file->getPathname(), $file->getClientOriginalName());
        }

        if ($file instanceof File || $file instanceof SplFileInfo) {
            return $this->normalizePathFilePart($name, $file->getRealPath() ?: $file->getPathname(), $file->getFilename());
        }

        if (is_string($file) && is_file($file)) {
            return $this->normalizePathFilePart($name, $file, basename($file));
        }

        if (is_array($file)) {
            $headers = $file['headers'] ?? [];
            $filename = $file['filename'] ?? $file['name'] ?? null;

            if (isset($file['path']) && is_string($file['path']) && $file['path'] !== '') {
                $part = $this->normalizePathFilePart($name, $file['path'], is_string($filename) && $filename !== '' ? $filename : null);
                $part['headers'] = $headers;

                return $part;
            }

            return array_filter([
                'name' => $name,
                'contents' => $this->rewindIfResource($file['contents'] ?? ''),
                'filename' => is_string($filename) && $filename !== '' ? $filename : null,
                'headers' => $headers,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return [
            'name' => $name,
            'contents' => $this->rewindIfResource($file),
            'filename' => $name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePathFilePart(string $name, string $path, mixed $filename = null): array
    {
        $contents = fopen($path, 'r');

        if ($contents === false) {
            throw new NetworkException("Unable to open multipart file [{$path}].");
        }

        return [
            'name' => $name,
            'contents' => $contents,
            'filename' => is_string($filename) && $filename !== '' ? $filename : basename($path),
        ];
    }

    private function isFileInput(mixed $value, bool $treatFilePathsAsFiles): bool
    {
        if ($value instanceof UploadedFile || $value instanceof File || $value instanceof SplFileInfo) {
            return true;
        }

        if ($treatFilePathsAsFiles && is_string($value) && is_file($value)) {
            return true;
        }

        return is_array($value) && (array_key_exists('contents', $value) || array_key_exists('path', $value));
    }

    private function rewindIfResource(mixed $contents): mixed
    {
        if (is_resource($contents)) {
            $meta = stream_get_meta_data($contents);

            if ($meta['seekable'] === true) {
                rewind($contents);
            }
        }

        return $contents;
    }

    private function appendPath(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function parseResponseBody(Response $response): mixed
    {
        $contentType = (string) $response->header('Content-Type');

        if (str_contains(strtolower($contentType), 'application/json')) {
            return $response->json();
        }

        $body = $response->body();

        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
    }

    private function buildErrorMessage(int $statusCode, mixed $responseBody): string
    {
        if (is_array($responseBody)) {
            foreach (['errorMessage', 'errorCode', 'detail', 'message', 'error', 'fault', 'faultstring'] as $key) {
                $value = $responseBody[$key] ?? null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        if (is_string($responseBody) && $responseBody !== '') {
            return $responseBody;
        }

        return "Request failed with status {$statusCode}";
    }

    private function resolveRetryPolicy(RetryPolicy|false|null $override): ?RetryPolicy
    {
        if ($override === false) {
            return null;
        }

        return $override ?? $this->retry;
    }

    private function shouldRetry(?RetryPolicy $policy, RetryDecisionContext $context): bool
    {
        if ($policy === null || $context->attempt >= $context->maxAttempts) {
            return false;
        }

        if ($policy->retryMethods !== [] && ! in_array($context->method, $policy->retryMethods, true)) {
            return false;
        }

        if ($context->status !== null) {
            if (! in_array($context->status, $policy->retryOnStatuses, true)) {
                return false;
            }
        } elseif (! $policy->retryOnNetworkError) {
            return false;
        }

        if (is_callable($policy->shouldRetry)) {
            return (bool) call_user_func($policy->shouldRetry, $context);
        }

        return true;
    }

    private function sleepBeforeRetry(?RetryPolicy $policy, int $attempt, ?Response $response): void
    {
        if ($policy === null) {
            return;
        }

        $delay = $this->retryAfterSeconds($policy, $response)
            ?? $this->backoffSeconds($policy, $attempt);

        if ($delay <= 0) {
            return;
        }

        if (is_callable($policy->sleeper)) {
            call_user_func($policy->sleeper, $delay);

            return;
        }

        usleep((int) round($delay * 1_000_000));
    }

    private function backoffSeconds(RetryPolicy $policy, int $attempt): float
    {
        $delay = $policy->baseDelaySeconds * ($policy->backoffMultiplier ** max(0, $attempt - 1));
        $delay = min($delay, $policy->maxDelaySeconds);

        if ($policy->jitterSeconds > 0.0) {
            $delay += mt_rand(0, (int) round($policy->jitterSeconds * 1_000_000)) / 1_000_000;
        }

        return $delay;
    }

    private function retryAfterSeconds(RetryPolicy $policy, ?Response $response): ?float
    {
        if (! $policy->respectRetryAfter || $response === null) {
            return null;
        }

        $header = trim((string) $response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return max(0.0, (float) $header);
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        return max(0.0, (float) ($timestamp - time()));
    }

    private function dispatchErrorHooks(
        BeforeRequestContext $context,
        mixed $error,
        ?Response $response = null,
        mixed $responseBody = null,
    ): void {
        foreach ($this->hooks?->onErrorCallbacks() ?? [] as $callback) {
            $callback(new ErrorContext(
                url: $context->url,
                path: $context->path,
                method: $context->method,
                headers: $context->headers,
                body: $context->body,
                attempt: $context->attempt,
                error: $error,
                response: $response,
                responseBody: $responseBody,
            ));
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0 && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function withoutHeader(array $headers, string $name): array
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                unset($headers[$key]);
            }
        }

        return $headers;
    }
}
