<?php

namespace NoriaLabs\Payments;

use Illuminate\Http\Client\Factory;
use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\Support\BusinessStatus;
use NoriaLabs\Payments\Support\Hooks;
use NoriaLabs\Payments\Support\HttpTransport;
use NoriaLabs\Payments\Support\RequestOptions;
use NoriaLabs\Payments\Support\RetryPolicy;
use NoriaLabs\Payments\Support\StaticAccessTokenProvider;

class PaystackClient
{
    public const BASE_URL = 'https://api.paystack.co';

    public const ENDPOINTS = [
        'initialize_transaction' => ['POST', '/transaction/initialize'],
        'charge_authorization' => ['POST', '/transaction/charge_authorization'],
        'partial_debit' => ['POST', '/transaction/partial_debit'],
        'verify_transaction' => ['GET', '/transaction/verify/{reference}'],
        'list_transactions' => ['GET', '/transaction'],
        'fetch_transaction' => ['GET', '/transaction/{id}'],
        'transaction_timeline' => ['GET', '/transaction/timeline/{id}'],
        'transaction_totals' => ['GET', '/transaction/totals'],
        'export_transactions' => ['GET', '/transaction/export'],

        'create_charge' => ['POST', '/charge'],
        'submit_charge_pin' => ['POST', '/charge/submit_pin'],
        'submit_charge_otp' => ['POST', '/charge/submit_otp'],
        'submit_charge_phone' => ['POST', '/charge/submit_phone'],
        'submit_charge_birthday' => ['POST', '/charge/submit_birthday'],
        'submit_charge_address' => ['POST', '/charge/submit_address'],
        'check_pending_charge' => ['GET', '/charge/{reference}'],

        'requery_capitec_pay_charge' => ['POST', '/capitec-pay/requery/{ref}'],

        'initiate_bulk_charge' => ['POST', '/bulkcharge'],
        'list_bulk_charge_batches' => ['GET', '/bulkcharge'],
        'fetch_bulk_charge_batch' => ['GET', '/bulkcharge/{code}'],
        'fetch_bulk_charge_batch_charges' => ['GET', '/bulkcharge/{code}/charges'],
        'pause_bulk_charge_batch' => ['GET', '/bulkcharge/pause/{code}'],
        'resume_bulk_charge_batch' => ['GET', '/bulkcharge/resume/{code}'],

        'create_subaccount' => ['POST', '/subaccount'],
        'list_subaccounts' => ['GET', '/subaccount'],
        'fetch_subaccount' => ['GET', '/subaccount/{code}'],
        'update_subaccount' => ['PUT', '/subaccount/{code}'],

        'create_split' => ['POST', '/split'],
        'list_splits' => ['GET', '/split'],
        'fetch_split' => ['GET', '/split/{id}'],
        'update_split' => ['PUT', '/split/{id}'],
        'add_subaccount_to_split' => ['POST', '/split/{id}/subaccount/add'],
        'remove_subaccount_from_split' => ['POST', '/split/{id}/subaccount/remove'],

        'send_terminal_event' => ['POST', '/terminal/{id}/event'],
        'fetch_terminal_event_status' => ['GET', '/terminal/{terminal_id}/event/{event_id}'],
        'fetch_terminal_status' => ['GET', '/terminal/{terminal_id}/presence'],
        'list_terminals' => ['GET', '/terminal'],
        'fetch_terminal' => ['GET', '/terminal/{terminal_id}'],
        'update_terminal' => ['PUT', '/terminal/{terminal_id}'],
        'commission_terminal' => ['POST', '/terminal/commission_device'],
        'decommission_terminal' => ['POST', '/terminal/decommission_device'],

        'create_virtual_terminal' => ['POST', '/virtual_terminal'],
        'list_virtual_terminals' => ['GET', '/virtual_terminal'],
        'fetch_virtual_terminal' => ['GET', '/virtual_terminal/{code}'],
        'update_virtual_terminal' => ['PUT', '/virtual_terminal/{code}'],
        'deactivate_virtual_terminal' => ['PUT', '/virtual_terminal/{code}/deactivate'],
        'assign_virtual_terminal_destination' => ['POST', '/virtual_terminal/{code}/destination/assign'],
        'unassign_virtual_terminal_destination' => ['POST', '/virtual_terminal/{code}/destination/unassign'],
        'add_virtual_terminal_split_code' => ['PUT', '/virtual_terminal/{code}/split_code'],
        'remove_virtual_terminal_split_code' => ['DELETE', '/virtual_terminal/{code}/split_code'],

        'create_customer' => ['POST', '/customer'],
        'list_customers' => ['GET', '/customer'],
        'fetch_customer' => ['GET', '/customer/{code}'],
        'update_customer' => ['PUT', '/customer/{code}'],
        'set_customer_risk_action' => ['POST', '/customer/set_risk_action'],
        'validate_customer' => ['POST', '/customer/{code}/identification'],
        'initialize_authorization' => ['POST', '/customer/authorization/initialize'],
        'verify_authorization' => ['GET', '/customer/authorization/verify/{reference}'],
        'deactivate_authorization' => ['POST', '/customer/authorization/deactivate'],
        'initialize_direct_debit' => ['POST', '/customer/{id}/initialize-direct-debit'],
        'customer_direct_debit_activation_charge' => ['PUT', '/customer/{id}/directdebit-activation-charge'],
        'customer_direct_debit_mandate_authorizations' => ['GET', '/customer/{id}/directdebit-mandate-authorizations'],

        'trigger_direct_debit_activation_charge' => ['PUT', '/directdebit/activation-charge'],
        'list_direct_debit_mandate_authorizations' => ['GET', '/directdebit/mandate-authorizations'],

        'create_dedicated_account' => ['POST', '/dedicated_account'],
        'list_dedicated_accounts' => ['GET', '/dedicated_account'],
        'assign_dedicated_account' => ['POST', '/dedicated_account/assign'],
        'fetch_dedicated_account' => ['GET', '/dedicated_account/{id}'],
        'deactivate_dedicated_account' => ['DELETE', '/dedicated_account/{id}'],
        'requery_dedicated_account' => ['GET', '/dedicated_account/requery'],
        'split_dedicated_account_transaction' => ['POST', '/dedicated_account/split'],
        'remove_split_from_dedicated_account' => ['DELETE', '/dedicated_account/split'],
        'fetch_dedicated_account_providers' => ['GET', '/dedicated_account/available_providers'],

        'register_apple_pay_domain' => ['POST', '/apple-pay/domain'],
        'list_apple_pay_domains' => ['GET', '/apple-pay/domain'],
        'unregister_apple_pay_domain' => ['DELETE', '/apple-pay/domain'],

        'create_plan' => ['POST', '/plan'],
        'list_plans' => ['GET', '/plan'],
        'fetch_plan' => ['GET', '/plan/{code}'],
        'update_plan' => ['PUT', '/plan/{code}'],

        'create_subscription' => ['POST', '/subscription'],
        'list_subscriptions' => ['GET', '/subscription'],
        'fetch_subscription' => ['GET', '/subscription/{code}'],
        'disable_subscription' => ['POST', '/subscription/disable'],
        'enable_subscription' => ['POST', '/subscription/enable'],
        'subscription_management_link' => ['GET', '/subscription/{code}/manage/link'],
        'send_subscription_management_email' => ['POST', '/subscription/{code}/manage/email'],

        'create_transfer_recipient' => ['POST', '/transferrecipient'],
        'list_transfer_recipients' => ['GET', '/transferrecipient'],
        'bulk_create_transfer_recipients' => ['POST', '/transferrecipient/bulk'],
        'fetch_transfer_recipient' => ['GET', '/transferrecipient/{code}'],
        'update_transfer_recipient' => ['PUT', '/transferrecipient/{code}'],
        'delete_transfer_recipient' => ['DELETE', '/transferrecipient/{code}'],

        'initiate_transfer' => ['POST', '/transfer'],
        'list_transfers' => ['GET', '/transfer'],
        'finalize_transfer' => ['POST', '/transfer/finalize_transfer'],
        'initiate_bulk_transfer' => ['POST', '/transfer/bulk'],
        'fetch_transfer' => ['GET', '/transfer/{code}'],
        'verify_transfer' => ['GET', '/transfer/verify/{reference}'],
        'export_transfers' => ['GET', '/transfer/export'],
        'resend_transfer_otp' => ['POST', '/transfer/resend_otp'],
        'disable_transfer_otp' => ['POST', '/transfer/disable_otp'],
        'finalize_disable_transfer_otp' => ['POST', '/transfer/disable_otp_finalize'],
        'enable_transfer_otp' => ['POST', '/transfer/enable_otp'],

        'balance' => ['GET', '/balance'],
        'balance_ledger' => ['GET', '/balance/ledger'],

        'create_payment_request' => ['POST', '/paymentrequest'],
        'list_payment_requests' => ['GET', '/paymentrequest'],
        'fetch_payment_request' => ['GET', '/paymentrequest/{id}'],
        'update_payment_request' => ['PUT', '/paymentrequest/{id}'],
        'verify_payment_request' => ['GET', '/paymentrequest/verify/{id}'],
        'notify_payment_request' => ['POST', '/paymentrequest/notify/{id}'],
        'payment_request_totals' => ['GET', '/paymentrequest/totals'],
        'finalize_payment_request' => ['POST', '/paymentrequest/finalize/{id}'],
        'archive_payment_request' => ['POST', '/paymentrequest/archive/{id}'],

        'create_product' => ['POST', '/product'],
        'list_products' => ['GET', '/product'],
        'fetch_product' => ['GET', '/product/{id}'],
        'update_product' => ['PUT', '/product/{id}'],
        'delete_product' => ['DELETE', '/product/{id}'],

        'create_storefront' => ['POST', '/storefront'],
        'list_storefronts' => ['GET', '/storefront'],
        'fetch_storefront' => ['GET', '/storefront/{id}'],
        'update_storefront' => ['PUT', '/storefront/{id}'],
        'delete_storefront' => ['DELETE', '/storefront/{id}'],
        'verify_storefront' => ['GET', '/storefront/verify/{slug}'],
        'list_storefront_orders' => ['GET', '/storefront/{id}/order'],
        'add_storefront_products' => ['POST', '/storefront/{id}/product'],
        'list_storefront_products' => ['GET', '/storefront/{id}/product'],
        'publish_storefront' => ['POST', '/storefront/{id}/publish'],
        'duplicate_storefront' => ['POST', '/storefront/{id}/duplicate'],

        'create_order' => ['POST', '/order'],
        'list_orders' => ['GET', '/order'],
        'fetch_order' => ['GET', '/order/{id}'],
        'list_product_orders' => ['GET', '/order/product/{id}'],
        'validate_order' => ['GET', '/order/{code}/validate'],

        'create_page' => ['POST', '/page'],
        'list_pages' => ['GET', '/page'],
        'fetch_page' => ['GET', '/page/{id}'],
        'update_page' => ['PUT', '/page/{id}'],
        'check_slug_availability' => ['GET', '/page/check_slug_availability/{slug}'],
        'add_products_to_page' => ['POST', '/page/{id}/product'],

        'list_settlements' => ['GET', '/settlement'],
        'list_settlement_transactions' => ['GET', '/settlement/{id}/transactions'],

        'fetch_payment_session_timeout' => ['GET', '/integration/payment_session_timeout'],
        'update_payment_session_timeout' => ['PUT', '/integration/payment_session_timeout'],

        'create_refund' => ['POST', '/refund'],
        'list_refunds' => ['GET', '/refund'],
        'retry_refund_with_customer_details' => ['POST', '/refund/retry_with_customer_details/{id}'],
        'fetch_refund' => ['GET', '/refund/{id}'],

        'list_disputes' => ['GET', '/dispute'],
        'fetch_dispute' => ['GET', '/dispute/{id}'],
        'update_dispute' => ['PUT', '/dispute/{id}'],
        'dispute_upload_url' => ['GET', '/dispute/{id}/upload_url'],
        'export_disputes' => ['GET', '/dispute/export'],
        'transaction_disputes' => ['GET', '/dispute/transaction/{id}'],
        'resolve_dispute' => ['PUT', '/dispute/{id}/resolve'],
        'add_dispute_evidence' => ['POST', '/dispute/{id}/evidence'],

        'list_banks' => ['GET', '/bank'],
        'resolve_bank_account' => ['GET', '/bank/resolve'],
        'validate_bank_account' => ['POST', '/bank/validate'],
        'resolve_card_bin' => ['GET', '/decision/bin/{bin}'],
        'list_countries' => ['GET', '/country'],
        'list_address_verification_states' => ['GET', '/address_verification/states'],
    ];

    /**
     * @param  array<string, array{0: string, 1: string}>  $endpoints
     */
    public function __construct(
        private readonly HttpTransport $http,
        private readonly AccessTokenProvider $tokens,
        private readonly array $endpoints = self::ENDPOINTS,
        private readonly bool $throwOnBusinessError = false,
        private readonly ?string $publicKey = null,
    ) {}

    public static function make(
        Factory $httpFactory,
        array $config = [],
        ?AccessTokenProvider $tokenProvider = null,
        ?Hooks $hooks = null,
    ): self {
        $transport = new HttpTransport(
            http: $httpFactory,
            baseUrl: (string) ($config['base_url'] ?? self::BASE_URL),
            timeoutSeconds: isset($config['timeout_seconds']) ? (float) $config['timeout_seconds'] : null,
            defaultHeaders: self::resolveDefaultHeaders($config),
            retry: RetryPolicy::fromArray($config['retry'] ?? null),
            hooks: $hooks,
        );

        return new self(
            http: $transport,
            tokens: $tokenProvider ?? self::secretKeyTokenProvider($config),
            endpoints: self::resolveEndpoints($config),
            throwOnBusinessError: self::boolean($config['throw_on_business_error'] ?? false),
            publicKey: self::nullableString($config['public_key'] ?? null),
        );
    }

    public function getAccessToken(bool $forceRefresh = false): string
    {
        return $this->tokens->getAccessToken($forceRefresh);
    }

    public function authorizedPost(string $path, array $payload = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendRawAuthorized($path, 'POST', $payload, [], $options);
    }

    public function authorizedGet(
        string $path,
        array $query = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->sendRawAuthorized($path, 'GET', null, $query, $options);
    }

    public function authorizedPut(string $path, array $payload = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendRawAuthorized($path, 'PUT', $payload, [], $options);
    }

    public function authorizedDelete(
        string $path,
        ?array $payload = null,
        array $query = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        return $this->sendRawAuthorized($path, 'DELETE', $payload, $query, $options);
    }

    public function initializeTransaction(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('initialize_transaction', payload: $payload, options: $options);
    }

    public function chargeAuthorization(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('charge_authorization', payload: $payload, options: $options);
    }

    public function partialDebit(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('partial_debit', payload: $payload, options: $options);
    }

    public function verifyTransaction(string|int $reference, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('verify_transaction', ['reference' => $reference], options: $options);
    }

    public function listTransactions(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_transactions', query: $query, options: $options);
    }

    public function fetchTransaction(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_transaction', ['id' => $id], options: $options);
    }

    public function transactionTimeline(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('transaction_timeline', ['id' => $id], options: $options);
    }

    public function transactionTotals(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('transaction_totals', query: $query, options: $options);
    }

    public function exportTransactions(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('export_transactions', query: $query, options: $options);
    }

    public function createCharge(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_charge', payload: $payload, options: $options);
    }

    public function submitChargePin(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('submit_charge_pin', payload: $payload, options: $options);
    }

    public function submitChargeOtp(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('submit_charge_otp', payload: $payload, options: $options);
    }

    public function submitChargePhone(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('submit_charge_phone', payload: $payload, options: $options);
    }

    public function submitChargeBirthday(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('submit_charge_birthday', payload: $payload, options: $options);
    }

    public function submitChargeAddress(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('submit_charge_address', payload: $payload, options: $options);
    }

    public function checkPendingCharge(string|int $reference, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('check_pending_charge', ['reference' => $reference], options: $options);
    }

    public function requeryCapitecPayCharge(
        string|int $reference,
        ?string $publicKey = null,
        array|RequestOptions|null $options = null,
    ): mixed {
        $publicKey ??= $this->publicKey;
        $resolved = RequestOptions::fromArray($options);

        if ($publicKey !== null && $resolved->accessToken === null) {
            $options = new RequestOptions(
                headers: $resolved->headers,
                timeoutSeconds: $resolved->timeoutSeconds,
                retry: $resolved->retry,
                accessToken: $publicKey,
                forceTokenRefresh: $resolved->forceTokenRefresh,
                amountNormalization: $resolved->amountNormalization,
                validate: $resolved->validate,
                throwOnBusinessError: $resolved->throwOnBusinessError,
            );
        }

        return $this->sendAuthorized('requery_capitec_pay_charge', ['ref' => $reference], options: $options);
    }

    public static function succeeded(mixed $response): ?bool
    {
        return BusinessStatus::succeeded(BusinessStatus::PAYSTACK, $response);
    }

    public static function statusMessage(mixed $response): ?string
    {
        return BusinessStatus::statusMessage(BusinessStatus::PAYSTACK, $response);
    }

    public function initiateBulkCharge(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('initiate_bulk_charge', payload: $payload, options: $options);
    }

    public function listBulkChargeBatches(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_bulk_charge_batches', query: $query, options: $options);
    }

    public function fetchBulkChargeBatch(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_bulk_charge_batch', ['code' => $code], options: $options);
    }

    public function fetchBulkChargeBatchCharges(string|int $code, array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_bulk_charge_batch_charges', ['code' => $code], query: $query, options: $options);
    }

    public function pauseBulkChargeBatch(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('pause_bulk_charge_batch', ['code' => $code], options: $options);
    }

    public function resumeBulkChargeBatch(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('resume_bulk_charge_batch', ['code' => $code], options: $options);
    }

    public function createSubaccount(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_subaccount', payload: $payload, options: $options);
    }

    public function listSubaccounts(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_subaccounts', query: $query, options: $options);
    }

    public function fetchSubaccount(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_subaccount', ['code' => $code], options: $options);
    }

    public function updateSubaccount(string|int $code, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_subaccount', ['code' => $code], payload: $payload, options: $options);
    }

    public function createSplit(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_split', payload: $payload, options: $options);
    }

    public function listSplits(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_splits', query: $query, options: $options);
    }

    public function fetchSplit(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_split', ['id' => $id], options: $options);
    }

    public function updateSplit(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_split', ['id' => $id], payload: $payload, options: $options);
    }

    public function addSubaccountToSplit(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('add_subaccount_to_split', ['id' => $id], payload: $payload, options: $options);
    }

    public function removeSubaccountFromSplit(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('remove_subaccount_from_split', ['id' => $id], payload: $payload, options: $options);
    }

    public function sendTerminalEvent(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('send_terminal_event', ['id' => $id], payload: $payload, options: $options);
    }

    public function fetchTerminalEventStatus(string|int $terminalId, string|int $eventId, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_terminal_event_status', [
            'terminal_id' => $terminalId,
            'event_id' => $eventId,
        ], options: $options);
    }

    public function fetchTerminalStatus(string|int $terminalId, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_terminal_status', ['terminal_id' => $terminalId], options: $options);
    }

    public function listTerminals(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_terminals', query: $query, options: $options);
    }

    public function fetchTerminal(string|int $terminalId, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_terminal', ['terminal_id' => $terminalId], options: $options);
    }

    public function updateTerminal(string|int $terminalId, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_terminal', ['terminal_id' => $terminalId], payload: $payload, options: $options);
    }

    public function commissionTerminal(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('commission_terminal', payload: $payload, options: $options);
    }

    public function decommissionTerminal(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('decommission_terminal', payload: $payload, options: $options);
    }

    public function createVirtualTerminal(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_virtual_terminal', payload: $payload, options: $options);
    }

    public function listVirtualTerminals(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_virtual_terminals', query: $query, options: $options);
    }

    public function fetchVirtualTerminal(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_virtual_terminal', ['code' => $code], options: $options);
    }

    public function updateVirtualTerminal(string|int $code, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_virtual_terminal', ['code' => $code], payload: $payload, options: $options);
    }

    public function deactivateVirtualTerminal(string|int $code, ?array $payload = null, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('deactivate_virtual_terminal', ['code' => $code], payload: $payload, options: $options);
    }

    public function assignVirtualTerminalDestination(string|int $code, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('assign_virtual_terminal_destination', ['code' => $code], payload: $payload, options: $options);
    }

    public function unassignVirtualTerminalDestination(string|int $code, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('unassign_virtual_terminal_destination', ['code' => $code], payload: $payload, options: $options);
    }

    public function addVirtualTerminalSplitCode(string|int $code, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('add_virtual_terminal_split_code', ['code' => $code], payload: $payload, options: $options);
    }

    public function removeVirtualTerminalSplitCode(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('remove_virtual_terminal_split_code', ['code' => $code], options: $options);
    }

    public function createCustomer(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_customer', payload: $payload, options: $options);
    }

    public function listCustomers(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_customers', query: $query, options: $options);
    }

    public function fetchCustomer(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_customer', ['code' => $code], options: $options);
    }

    public function updateCustomer(string|int $code, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_customer', ['code' => $code], payload: $payload, options: $options);
    }

    public function setCustomerRiskAction(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('set_customer_risk_action', payload: $payload, options: $options);
    }

    public function validateCustomer(string|int $code, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('validate_customer', ['code' => $code], payload: $payload, options: $options);
    }

    public function initializeAuthorization(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('initialize_authorization', payload: $payload, options: $options);
    }

    public function verifyAuthorization(string|int $reference, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('verify_authorization', ['reference' => $reference], options: $options);
    }

    public function deactivateAuthorization(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('deactivate_authorization', payload: $payload, options: $options);
    }

    public function initializeDirectDebit(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('initialize_direct_debit', ['id' => $id], payload: $payload, options: $options);
    }

    public function customerDirectDebitActivationCharge(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('customer_direct_debit_activation_charge', ['id' => $id], payload: $payload, options: $options);
    }

    public function customerDirectDebitMandateAuthorizations(string|int $id, array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('customer_direct_debit_mandate_authorizations', ['id' => $id], query: $query, options: $options);
    }

    public function triggerDirectDebitActivationCharge(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('trigger_direct_debit_activation_charge', payload: $payload, options: $options);
    }

    public function listDirectDebitMandateAuthorizations(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_direct_debit_mandate_authorizations', query: $query, options: $options);
    }

    public function createDedicatedAccount(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_dedicated_account', payload: $payload, options: $options);
    }

    public function listDedicatedAccounts(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_dedicated_accounts', query: $query, options: $options);
    }

    public function assignDedicatedAccount(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('assign_dedicated_account', payload: $payload, options: $options);
    }

    public function fetchDedicatedAccount(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_dedicated_account', ['id' => $id], options: $options);
    }

    public function deactivateDedicatedAccount(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('deactivate_dedicated_account', ['id' => $id], options: $options);
    }

    public function requeryDedicatedAccount(array $query, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('requery_dedicated_account', query: $query, options: $options);
    }

    public function splitDedicatedAccountTransaction(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('split_dedicated_account_transaction', payload: $payload, options: $options);
    }

    public function removeSplitFromDedicatedAccount(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('remove_split_from_dedicated_account', payload: $payload, options: $options);
    }

    public function fetchDedicatedAccountProviders(array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_dedicated_account_providers', options: $options);
    }

    public function registerApplePayDomain(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('register_apple_pay_domain', payload: $payload, options: $options);
    }

    public function listApplePayDomains(array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_apple_pay_domains', options: $options);
    }

    public function unregisterApplePayDomain(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('unregister_apple_pay_domain', payload: $payload, options: $options);
    }

    public function createPlan(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_plan', payload: $payload, options: $options);
    }

    public function listPlans(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_plans', query: $query, options: $options);
    }

    public function fetchPlan(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_plan', ['code' => $code], options: $options);
    }

    public function updatePlan(string|int $code, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_plan', ['code' => $code], payload: $payload, options: $options);
    }

    public function createSubscription(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_subscription', payload: $payload, options: $options);
    }

    public function listSubscriptions(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_subscriptions', query: $query, options: $options);
    }

    public function fetchSubscription(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_subscription', ['code' => $code], options: $options);
    }

    public function disableSubscription(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('disable_subscription', payload: $payload, options: $options);
    }

    public function enableSubscription(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('enable_subscription', payload: $payload, options: $options);
    }

    public function subscriptionManagementLink(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('subscription_management_link', ['code' => $code], options: $options);
    }

    public function sendSubscriptionManagementEmail(string|int $code, ?array $payload = null, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('send_subscription_management_email', ['code' => $code], payload: $payload, options: $options);
    }

    public function createTransferRecipient(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_transfer_recipient', payload: $payload, options: $options);
    }

    public function listTransferRecipients(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_transfer_recipients', query: $query, options: $options);
    }

    public function bulkCreateTransferRecipients(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('bulk_create_transfer_recipients', payload: $payload, options: $options);
    }

    public function fetchTransferRecipient(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_transfer_recipient', ['code' => $code], options: $options);
    }

    public function updateTransferRecipient(string|int $code, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_transfer_recipient', ['code' => $code], payload: $payload, options: $options);
    }

    public function deleteTransferRecipient(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('delete_transfer_recipient', ['code' => $code], options: $options);
    }

    public function initiateTransfer(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('initiate_transfer', payload: $payload, options: $options);
    }

    public function listTransfers(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_transfers', query: $query, options: $options);
    }

    public function finalizeTransfer(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('finalize_transfer', payload: $payload, options: $options);
    }

    public function initiateBulkTransfer(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('initiate_bulk_transfer', payload: $payload, options: $options);
    }

    public function fetchTransfer(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_transfer', ['code' => $code], options: $options);
    }

    public function verifyTransfer(string|int $reference, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('verify_transfer', ['reference' => $reference], options: $options);
    }

    public function exportTransfers(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('export_transfers', query: $query, options: $options);
    }

    public function resendTransferOtp(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('resend_transfer_otp', payload: $payload, options: $options);
    }

    public function disableTransferOtp(array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('disable_transfer_otp', options: $options);
    }

    public function finalizeDisableTransferOtp(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('finalize_disable_transfer_otp', payload: $payload, options: $options);
    }

    public function enableTransferOtp(array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('enable_transfer_otp', options: $options);
    }

    public function balance(array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('balance', options: $options);
    }

    public function balanceLedger(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('balance_ledger', query: $query, options: $options);
    }

    public function createPaymentRequest(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_payment_request', payload: $payload, options: $options);
    }

    public function listPaymentRequests(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_payment_requests', query: $query, options: $options);
    }

    public function fetchPaymentRequest(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_payment_request', ['id' => $id], options: $options);
    }

    public function updatePaymentRequest(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_payment_request', ['id' => $id], payload: $payload, options: $options);
    }

    public function verifyPaymentRequest(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('verify_payment_request', ['id' => $id], options: $options);
    }

    public function notifyPaymentRequest(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('notify_payment_request', ['id' => $id], options: $options);
    }

    public function paymentRequestTotals(array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('payment_request_totals', options: $options);
    }

    public function finalizePaymentRequest(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('finalize_payment_request', ['id' => $id], options: $options);
    }

    public function archivePaymentRequest(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('archive_payment_request', ['id' => $id], options: $options);
    }

    public function createProduct(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_product', payload: $payload, options: $options);
    }

    public function listProducts(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_products', query: $query, options: $options);
    }

    public function fetchProduct(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_product', ['id' => $id], options: $options);
    }

    public function updateProduct(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_product', ['id' => $id], payload: $payload, options: $options);
    }

    public function deleteProduct(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('delete_product', ['id' => $id], options: $options);
    }

    public function createStorefront(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_storefront', payload: $payload, options: $options);
    }

    public function listStorefronts(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_storefronts', query: $query, options: $options);
    }

    public function fetchStorefront(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_storefront', ['id' => $id], options: $options);
    }

    public function updateStorefront(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_storefront', ['id' => $id], payload: $payload, options: $options);
    }

    public function deleteStorefront(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('delete_storefront', ['id' => $id], options: $options);
    }

    public function verifyStorefront(string $slug, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('verify_storefront', ['slug' => $slug], options: $options);
    }

    public function listStorefrontOrders(string|int $id, array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_storefront_orders', ['id' => $id], query: $query, options: $options);
    }

    public function addStorefrontProducts(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('add_storefront_products', ['id' => $id], payload: $payload, options: $options);
    }

    public function listStorefrontProducts(string|int $id, array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_storefront_products', ['id' => $id], query: $query, options: $options);
    }

    public function publishStorefront(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('publish_storefront', ['id' => $id], options: $options);
    }

    public function duplicateStorefront(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('duplicate_storefront', ['id' => $id], options: $options);
    }

    public function createOrder(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_order', payload: $payload, options: $options);
    }

    public function listOrders(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_orders', query: $query, options: $options);
    }

    public function fetchOrder(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_order', ['id' => $id], options: $options);
    }

    public function listProductOrders(string|int $id, array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_product_orders', ['id' => $id], query: $query, options: $options);
    }

    public function validateOrder(string|int $code, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('validate_order', ['code' => $code], options: $options);
    }

    public function createPage(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_page', payload: $payload, options: $options);
    }

    public function listPages(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_pages', query: $query, options: $options);
    }

    public function fetchPage(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_page', ['id' => $id], options: $options);
    }

    public function updatePage(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_page', ['id' => $id], payload: $payload, options: $options);
    }

    public function checkSlugAvailability(string $slug, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('check_slug_availability', ['slug' => $slug], options: $options);
    }

    public function addProductsToPage(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('add_products_to_page', ['id' => $id], payload: $payload, options: $options);
    }

    public function listSettlements(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_settlements', query: $query, options: $options);
    }

    public function listSettlementTransactions(string|int $id, array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_settlement_transactions', ['id' => $id], query: $query, options: $options);
    }

    public function fetchPaymentSessionTimeout(array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_payment_session_timeout', options: $options);
    }

    public function updatePaymentSessionTimeout(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_payment_session_timeout', payload: $payload, options: $options);
    }

    public function createRefund(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('create_refund', payload: $payload, options: $options);
    }

    public function listRefunds(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_refunds', query: $query, options: $options);
    }

    public function retryRefundWithCustomerDetails(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('retry_refund_with_customer_details', ['id' => $id], payload: $payload, options: $options);
    }

    public function fetchRefund(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_refund', ['id' => $id], options: $options);
    }

    public function listDisputes(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_disputes', query: $query, options: $options);
    }

    public function fetchDispute(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('fetch_dispute', ['id' => $id], options: $options);
    }

    public function updateDispute(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('update_dispute', ['id' => $id], payload: $payload, options: $options);
    }

    public function disputeUploadUrl(string|int $id, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('dispute_upload_url', ['id' => $id], options: $options);
    }

    public function exportDisputes(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('export_disputes', query: $query, options: $options);
    }

    public function transactionDisputes(string|int $id, array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('transaction_disputes', ['id' => $id], query: $query, options: $options);
    }

    public function resolveDispute(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('resolve_dispute', ['id' => $id], payload: $payload, options: $options);
    }

    public function addDisputeEvidence(string|int $id, array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('add_dispute_evidence', ['id' => $id], payload: $payload, options: $options);
    }

    public function listBanks(array $query = [], array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_banks', query: $query, options: $options);
    }

    public function resolveBankAccount(array $query, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('resolve_bank_account', query: $query, options: $options);
    }

    public function validateBankAccount(array $payload, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('validate_bank_account', payload: $payload, options: $options);
    }

    public function resolveCardBin(string|int $bin, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('resolve_card_bin', ['bin' => $bin], options: $options);
    }

    public function listCountries(array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_countries', options: $options);
    }

    public function listAddressVerificationStates(array $query, array|RequestOptions|null $options = null): mixed
    {
        return $this->sendAuthorized('list_address_verification_states', query: $query, options: $options);
    }

    private function sendRawAuthorized(
        string $path,
        string $method,
        ?array $payload,
        array $query,
        array|RequestOptions|null $options,
    ): mixed {
        $requestOptions = RequestOptions::fromArray($options);
        $token = $requestOptions->accessToken ?? $this->tokens->getAccessToken($requestOptions->forceTokenRefresh);

        $headers = array_merge($requestOptions->headers, [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        return $this->assertBusinessStatus(
            $this->http->send(
                path: $path,
                method: $method,
                headers: $headers,
                query: $query,
                body: $payload,
                timeoutSeconds: $requestOptions->timeoutSeconds,
                retry: $requestOptions->retry,
            ),
            $requestOptions,
            "Paystack {$method} {$path}",
        );
    }

    private function sendAuthorized(
        string $name,
        array $pathParameters = [],
        ?array $payload = null,
        array $query = [],
        array|RequestOptions|null $options = null,
    ): mixed {
        [$method, $path] = $this->resolveEndpoint($name, $pathParameters);
        $requestOptions = RequestOptions::fromArray($options);
        $token = $requestOptions->accessToken ?? $this->tokens->getAccessToken($requestOptions->forceTokenRefresh);

        $headers = array_merge($requestOptions->headers, [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        return $this->assertBusinessStatus(
            $this->http->send(
                path: $path,
                method: $method,
                headers: $headers,
                query: $query,
                body: $payload,
                timeoutSeconds: $requestOptions->timeoutSeconds,
                retry: $requestOptions->retry,
            ),
            $requestOptions,
            "Paystack {$method} {$path}",
        );
    }

    private function assertBusinessStatus(mixed $response, RequestOptions $options, string $context): mixed
    {
        if ($options->throwOnBusinessError ?? $this->throwOnBusinessError) {
            BusinessStatus::assert(BusinessStatus::PAYSTACK, $response, $context);
        }

        return $response;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveEndpoint(string $name, array $replacements = []): array
    {
        if (! array_key_exists($name, $this->endpoints)) {
            throw new ConfigurationException("Unknown Paystack endpoint [{$name}].");
        }

        [$method, $path] = $this->endpoints[$name];

        foreach ($replacements as $key => $value) {
            $path = str_replace('{'.$key.'}', rawurlencode((string) $value), $path);
        }

        return [strtoupper($method), $path];
    }

    private static function secretKeyTokenProvider(array $config): AccessTokenProvider
    {
        $secretKey = self::nullableString($config['secret_key'] ?? null);

        if ($secretKey === null) {
            throw new ConfigurationException(
                'PaystackClient requires either secret_key or a custom token provider.'
            );
        }

        return new StaticAccessTokenProvider($secretKey);
    }

    private static function resolveEndpoints(array $config): array
    {
        $endpoints = self::ENDPOINTS;

        foreach ((array) ($config['endpoints'] ?? []) as $name => $override) {
            if (! is_string($name) || ! array_key_exists($name, $endpoints)) {
                continue;
            }

            if (is_string($override) && $override !== '') {
                $endpoints[$name] = [$endpoints[$name][0], $override];
            }

            if (is_array($override)) {
                $method = $override['method'] ?? $endpoints[$name][0];
                $path = $override['path'] ?? null;

                if ($path !== null && $path !== '') {
                    $endpoints[$name] = [strtoupper((string) $method), (string) $path];
                }
            }
        }

        return $endpoints;
    }

    private static function resolveDefaultHeaders(array $config): array
    {
        $headers = (array) ($config['default_headers'] ?? []);
        $userAgent = $config['user_agent'] ?? null;

        if (is_string($userAgent) && $userAgent !== '' && ! self::hasHeader($headers, 'User-Agent')) {
            $headers['User-Agent'] = $userAgent;
        }

        return $headers;
    }

    private static function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
