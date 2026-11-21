<?php

namespace Noria\Payments;

use Illuminate\Http\Client\Factory;
use Noria\Payments\Exceptions\ApiException;
use Noria\Payments\Exceptions\ConfigurationException;
use Noria\Payments\Exceptions\NoriaPayException;
use Noria\Payments\Support\HttpTransport;
use Noria\Payments\Support\Setting;

class NoriaPayClient
{
    public function __construct(
        private readonly HttpTransport $http,
        private readonly string $apiKey,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(Factory $httpFactory, array $config = []): self
    {
        $baseUrl = Setting::string($config['base_url'] ?? null, '');
        $apiKey = Setting::string($config['api_key'] ?? null, '');

        if ($baseUrl === '' || $apiKey === '') {
            throw new ConfigurationException('NoriaPayClient requires base_url and api_key.');
        }

        $headers = ['Accept' => 'application/json'];
        $userAgent = Setting::string($config['user_agent'] ?? null, '');
        if ($userAgent !== '') {
            $headers['User-Agent'] = $userAgent;
        }

        return new self(
            http: new HttpTransport(
                http: $httpFactory,
                baseUrl: $baseUrl,
                timeoutSeconds: Setting::float($config['timeout_seconds'] ?? null) ?? 30.0,
                defaultHeaders: $headers,
            ),
            apiKey: $apiKey,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function charge(array $body, string $idempotencyKey, ?string $project = null): array
    {
        return $this->send('POST', '/v1/charges', $body, project: $project, idempotencyKey: $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function recordCharge(array $body, string $idempotencyKey, ?string $project = null): array
    {
        return $this->send('POST', '/v1/charges/record', $body, project: $project, idempotencyKey: $idempotencyKey);
    }

    /**
     * A transaction, or a payout_request when the project needs a second person to approve payouts.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function payout(array $body, string $idempotencyKey, ?string $project = null): array
    {
        return $this->send('POST', '/v1/payouts', $body, project: $project, idempotencyKey: $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function refund(array $body, string $idempotencyKey, ?string $project = null): array
    {
        return $this->send('POST', '/v1/refunds', $body, project: $project, idempotencyKey: $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelCharge(string $id, ?string $project = null): array
    {
        return $this->send('POST', '/v1/charges/'.rawurlencode($id).'/cancel', project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function transaction(string $id, ?string $project = null): array
    {
        return $this->send('GET', '/v1/transactions/'.rawurlencode($id), project: $project);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function transactions(array $query = [], ?string $project = null): array
    {
        return $this->send('GET', '/v1/transactions', query: $query, project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function transactionEvents(string $id, ?string $project = null): array
    {
        return $this->send('GET', '/v1/transactions/'.rawurlencode($id).'/events', project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function checkStatus(string $id, ?string $project = null): array
    {
        return $this->send('POST', '/v1/transactions/'.rawurlencode($id).'/status-check', project: $project);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createPaymentLink(array $body, ?string $project = null): array
    {
        return $this->send('POST', '/v1/payment-links', $body, project: $project);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function updatePaymentLink(string $id, array $body, ?string $project = null): array
    {
        return $this->send('PATCH', '/v1/payment-links/'.rawurlencode($id), $body, project: $project);
    }

    public function closePaymentLink(string $id, ?string $project = null): void
    {
        $this->send('DELETE', '/v1/payment-links/'.rawurlencode($id), project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentLink(string $id, ?string $project = null): array
    {
        return $this->send('GET', '/v1/payment-links/'.rawurlencode($id), project: $project);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function paymentMethods(array $query = [], ?string $project = null): array
    {
        return $this->send('GET', '/v1/payment-methods', query: $query, project: $project);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function configurePaymentMethod(string $provider, array $body, ?string $project = null): array
    {
        return $this->send('PUT', '/v1/payment-methods/'.rawurlencode($provider), $body, project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function setPaymentMethodEnabled(string $provider, bool $enabled, ?string $mode = null, ?string $project = null, ?string $purpose = null): array
    {
        return $this->send('PATCH', '/v1/payment-methods/'.rawurlencode($provider), ['enabled' => $enabled], query: ['mode' => $mode, 'purpose' => $purpose], project: $project);
    }

    public function removePaymentMethod(string $provider, ?string $mode = null, ?string $project = null, ?string $purpose = null): void
    {
        $this->send('DELETE', '/v1/payment-methods/'.rawurlencode($provider), query: ['mode' => $mode, 'purpose' => $purpose], project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyPaymentMethod(string $provider, ?string $mode = null, ?string $project = null, ?string $purpose = null): array
    {
        return $this->send('POST', '/v1/payment-methods/'.rawurlencode($provider).'/verify', query: ['mode' => $mode, 'purpose' => $purpose], project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function registerC2B(string $provider, ?string $mode = null, ?string $project = null, ?string $purpose = null): array
    {
        return $this->send('POST', '/v1/payment-methods/'.rawurlencode($provider).'/c2b-registration', query: ['mode' => $mode, 'purpose' => $purpose], project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function registerPull(string $provider, ?string $mode = null, ?string $project = null, ?string $purpose = null): array
    {
        return $this->send('POST', '/v1/payment-methods/'.rawurlencode($provider).'/pull-registration', query: ['mode' => $mode, 'purpose' => $purpose], project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function balance(string $provider, ?string $mode = null, ?string $project = null, ?string $purpose = null): array
    {
        return $this->send('GET', '/v1/payment-methods/'.rawurlencode($provider).'/balance', query: ['mode' => $mode, 'purpose' => $purpose], project: $project);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function lookup(string $provider, array $body, ?string $project = null): array
    {
        return $this->send('POST', '/v1/payment-methods/'.rawurlencode($provider).'/lookups', $body, project: $project);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function recordLookup(string $provider, array $body, ?string $project = null): array
    {
        return $this->send('POST', '/v1/payment-methods/'.rawurlencode($provider).'/lookups/record', $body, project: $project);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function reconcileLookup(string $provider, array $body, ?string $project = null): array
    {
        return $this->send('POST', '/v1/payment-methods/'.rawurlencode($provider).'/lookups/reconcile', $body, project: $project);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function importStatement(array $body, ?string $project = null): array
    {
        return $this->send('POST', '/v1/statements', $body, project: $project);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function c2bPayments(array $query = [], ?string $project = null): array
    {
        return $this->send('GET', '/v1/c2b-payments', query: $query, project: $project);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function projects(array $query = []): array
    {
        return $this->send('GET', '/v1/applications', query: $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createProject(array $body): array
    {
        return $this->send('POST', '/v1/applications', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function project(string $id): array
    {
        return $this->send('GET', '/v1/applications/'.rawurlencode($id));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function updateProject(string $id, array $body): array
    {
        return $this->send('PATCH', '/v1/applications/'.rawurlencode($id), $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function webhookEndpoints(?string $project = null): array
    {
        return $this->send('GET', '/v1/webhook-endpoints', project: $project);
    }

    /**
     * The response carries the signing secret once; store it.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createWebhookEndpoint(array $body, ?string $project = null): array
    {
        return $this->send('POST', '/v1/webhook-endpoints', $body, project: $project);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function updateWebhookEndpoint(string $id, array $body, ?string $project = null): array
    {
        return $this->send('PATCH', '/v1/webhook-endpoints/'.rawurlencode($id), $body, project: $project);
    }

    public function deleteWebhookEndpoint(string $id, ?string $project = null): void
    {
        $this->send('DELETE', '/v1/webhook-endpoints/'.rawurlencode($id), project: $project);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function webhookDeliveries(array $query = [], ?string $project = null): array
    {
        return $this->send('GET', '/v1/webhook-deliveries', query: $query, project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function retryWebhookDelivery(string $id, ?string $project = null): array
    {
        return $this->send('POST', '/v1/webhook-deliveries/'.rawurlencode($id).'/retry', project: $project);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function payoutRequests(array $query = [], ?string $project = null): array
    {
        return $this->send('GET', '/v1/payout-requests', query: $query, project: $project);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function startOnboarding(string $kind, string $reference, array $details, string $idempotencyKey, ?string $project = null): array
    {
        return $this->send('POST', '/v1/onboarding-cases', ['kind' => $kind, 'reference' => $reference, 'details' => $details], project: $project, idempotencyKey: $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function onboardingCase(string $id, ?string $project = null): array
    {
        return $this->send('GET', '/v1/onboarding-cases/'.rawurlencode($id), project: $project);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function onboardingCases(array $query = [], ?string $project = null): array
    {
        return $this->send('GET', '/v1/onboarding-cases', query: $query, project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmOnboarding(string $id, string $otp, ?string $project = null): array
    {
        return $this->send('POST', '/v1/onboarding-cases/'.rawurlencode($id).'/confirm', ['otp' => $otp], project: $project);
    }

    /**
     * @param  array<string, string>  $fields
     * @param  list<array{field: string, filename: string, content_type: string, contents: string}>  $files
     * @return array<string, mixed>
     */
    public function submitOnboardingKyc(string $id, array $fields, array $files, ?string $project = null): array
    {
        $encoded = array_map(static fn (array $file): array => [
            'field' => $file['field'],
            'filename' => $file['filename'],
            'content_type' => $file['content_type'],
            'content_base64' => base64_encode($file['contents']),
        ], $files);

        return $this->send('POST', '/v1/onboarding-cases/'.rawurlencode($id).'/kyc', ['fields' => (object) $fields, 'files' => $encoded], project: $project, timeoutSeconds: 120.0);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return list<array<string, mixed>>
     */
    public function onboardingReferenceData(string $list, array $query = [], ?string $project = null): array
    {
        $response = $this->send('GET', '/v1/onboarding/reference-data/'.rawurlencode($list), query: $query, project: $project);
        $items = [];
        foreach ((array) ($response['data'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = Setting::map($item);
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function providers(?string $project = null): array
    {
        return $this->send('GET', '/v1/providers', project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function channelCodes(?string $project = null): array
    {
        return $this->send('GET', '/v1/channel-codes', project: $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentOptions(?string $mode = null, ?string $project = null): array
    {
        return $this->send('GET', '/v1/payment-options', query: ['mode' => $mode], project: $project);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function send(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        ?string $project = null,
        ?string $idempotencyKey = null,
        ?float $timeoutSeconds = null,
    ): array {
        $headers = ['Authorization' => 'Bearer '.$this->apiKey];
        if ($project !== null && $project !== '') {
            $headers['X-Noria-Application'] = $project;
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            $response = $this->http->send(
                path: $path,
                method: $method,
                headers: $headers,
                query: Setting::query(array_filter($query, static fn (mixed $value): bool => $value !== null)),
                body: $body,
                timeoutSeconds: $timeoutSeconds,
                retry: false,
            );
        } catch (ApiException $exception) {
            throw NoriaPayException::from($exception);
        }

        return Setting::map($response);
    }
}
