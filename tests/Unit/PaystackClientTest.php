<?php

use Illuminate\Support\Facades\Http;
use NoriaLabs\Payments\Contracts\AccessTokenProvider;
use NoriaLabs\Payments\Exceptions\BusinessException;
use NoriaLabs\Payments\Exceptions\ConfigurationException;
use NoriaLabs\Payments\PaystackClient;
use NoriaLabs\Payments\Support\HttpTransport;
use NoriaLabs\Payments\Support\RequestOptions;

function paystackTokenProvider(string $token = 'sk_test_provider'): AccessTokenProvider
{
    return new class($token) implements AccessTokenProvider
    {
        public function __construct(private readonly string $token) {}

        public function getAccessToken(bool $forceRefresh = false): string
        {
            return $forceRefresh ? $this->token.'_fresh' : $this->token;
        }
    };
}

function paystackClient(array $config = [], ?AccessTokenProvider $tokenProvider = null): PaystackClient
{
    return PaystackClient::make(
        Http::getFacadeRoot(),
        array_replace(['secret_key' => 'sk_test_default'], $config),
        $tokenProvider,
    );
}

function paystackPathValues(): array
{
    return [
        'reference' => 'ref_123',
        'id' => '123',
        'code' => 'CODE_123',
        'terminal_id' => 'TERM_123',
        'event_id' => 'EVT_123',
        'slug' => 'store-slug',
        'bin' => '539983',
        'ref' => 'ref_123',
    ];
}

function paystackEndpointCalls(): array
{
    $v = paystackPathValues();

    return [
        'initialize_transaction' => fn (PaystackClient $client) => $client->initializeTransaction(['email' => 'customer@example.com', 'amount' => 10000]),
        'charge_authorization' => fn (PaystackClient $client) => $client->chargeAuthorization(['authorization_code' => 'AUTH_code', 'email' => 'customer@example.com', 'amount' => 10000]),
        'partial_debit' => fn (PaystackClient $client) => $client->partialDebit(['authorization_code' => 'AUTH_code', 'currency' => 'NGN', 'amount' => 1000]),
        'verify_transaction' => fn (PaystackClient $client) => $client->verifyTransaction($v['reference']),
        'list_transactions' => fn (PaystackClient $client) => $client->listTransactions(['perPage' => 1]),
        'fetch_transaction' => fn (PaystackClient $client) => $client->fetchTransaction($v['id']),
        'transaction_timeline' => fn (PaystackClient $client) => $client->transactionTimeline($v['id']),
        'transaction_totals' => fn (PaystackClient $client) => $client->transactionTotals(['from' => '2026-04-01']),
        'export_transactions' => fn (PaystackClient $client) => $client->exportTransactions(['settled' => true]),

        'create_charge' => fn (PaystackClient $client) => $client->createCharge(['email' => 'customer@example.com', 'amount' => 10000]),
        'submit_charge_pin' => fn (PaystackClient $client) => $client->submitChargePin(['pin' => '1234', 'reference' => $v['reference']]),
        'submit_charge_otp' => fn (PaystackClient $client) => $client->submitChargeOtp(['otp' => '123456', 'reference' => $v['reference']]),
        'submit_charge_phone' => fn (PaystackClient $client) => $client->submitChargePhone(['phone' => '08000000000', 'reference' => $v['reference']]),
        'submit_charge_birthday' => fn (PaystackClient $client) => $client->submitChargeBirthday(['birthday' => '1990-01-01', 'reference' => $v['reference']]),
        'submit_charge_address' => fn (PaystackClient $client) => $client->submitChargeAddress(['address' => '1 Test Street', 'reference' => $v['reference']]),
        'check_pending_charge' => fn (PaystackClient $client) => $client->checkPendingCharge($v['reference']),

        'requery_capitec_pay_charge' => fn (PaystackClient $client) => $client->requeryCapitecPayCharge($v['ref'], 'pk_test_public'),

        'initiate_bulk_charge' => fn (PaystackClient $client) => $client->initiateBulkCharge(['batch' => []]),
        'list_bulk_charge_batches' => fn (PaystackClient $client) => $client->listBulkChargeBatches(['perPage' => 1]),
        'fetch_bulk_charge_batch' => fn (PaystackClient $client) => $client->fetchBulkChargeBatch($v['code']),
        'fetch_bulk_charge_batch_charges' => fn (PaystackClient $client) => $client->fetchBulkChargeBatchCharges($v['code'], ['perPage' => 1]),
        'pause_bulk_charge_batch' => fn (PaystackClient $client) => $client->pauseBulkChargeBatch($v['code']),
        'resume_bulk_charge_batch' => fn (PaystackClient $client) => $client->resumeBulkChargeBatch($v['code']),

        'create_subaccount' => fn (PaystackClient $client) => $client->createSubaccount(['business_name' => 'Noria']),
        'list_subaccounts' => fn (PaystackClient $client) => $client->listSubaccounts(['perPage' => 1]),
        'fetch_subaccount' => fn (PaystackClient $client) => $client->fetchSubaccount($v['code']),
        'update_subaccount' => fn (PaystackClient $client) => $client->updateSubaccount($v['code'], ['description' => 'updated']),

        'create_split' => fn (PaystackClient $client) => $client->createSplit(['name' => 'Split']),
        'list_splits' => fn (PaystackClient $client) => $client->listSplits(['active' => true]),
        'fetch_split' => fn (PaystackClient $client) => $client->fetchSplit($v['id']),
        'update_split' => fn (PaystackClient $client) => $client->updateSplit($v['id'], ['name' => 'Updated']),
        'add_subaccount_to_split' => fn (PaystackClient $client) => $client->addSubaccountToSplit($v['id'], ['subaccount' => $v['code']]),
        'remove_subaccount_from_split' => fn (PaystackClient $client) => $client->removeSubaccountFromSplit($v['id'], ['subaccount' => $v['code']]),

        'send_terminal_event' => fn (PaystackClient $client) => $client->sendTerminalEvent($v['id'], ['type' => 'invoice']),
        'fetch_terminal_event_status' => fn (PaystackClient $client) => $client->fetchTerminalEventStatus($v['terminal_id'], $v['event_id']),
        'fetch_terminal_status' => fn (PaystackClient $client) => $client->fetchTerminalStatus($v['terminal_id']),
        'list_terminals' => fn (PaystackClient $client) => $client->listTerminals(['perPage' => 1]),
        'fetch_terminal' => fn (PaystackClient $client) => $client->fetchTerminal($v['terminal_id']),
        'update_terminal' => fn (PaystackClient $client) => $client->updateTerminal($v['terminal_id'], ['name' => 'Front desk']),
        'commission_terminal' => fn (PaystackClient $client) => $client->commissionTerminal(['serial_number' => 'SN123']),
        'decommission_terminal' => fn (PaystackClient $client) => $client->decommissionTerminal(['serial_number' => 'SN123']),

        'create_virtual_terminal' => fn (PaystackClient $client) => $client->createVirtualTerminal(['name' => 'VT']),
        'list_virtual_terminals' => fn (PaystackClient $client) => $client->listVirtualTerminals(['perPage' => 1]),
        'fetch_virtual_terminal' => fn (PaystackClient $client) => $client->fetchVirtualTerminal($v['code']),
        'update_virtual_terminal' => fn (PaystackClient $client) => $client->updateVirtualTerminal($v['code'], ['name' => 'VT2']),
        'deactivate_virtual_terminal' => fn (PaystackClient $client) => $client->deactivateVirtualTerminal($v['code']),
        'assign_virtual_terminal_destination' => fn (PaystackClient $client) => $client->assignVirtualTerminalDestination($v['code'], ['target' => 'subaccount']),
        'unassign_virtual_terminal_destination' => fn (PaystackClient $client) => $client->unassignVirtualTerminalDestination($v['code'], ['target' => 'subaccount']),
        'add_virtual_terminal_split_code' => fn (PaystackClient $client) => $client->addVirtualTerminalSplitCode($v['code'], ['split_code' => 'SPL_123']),
        'remove_virtual_terminal_split_code' => fn (PaystackClient $client) => $client->removeVirtualTerminalSplitCode($v['code']),

        'create_customer' => fn (PaystackClient $client) => $client->createCustomer(['email' => 'customer@example.com']),
        'list_customers' => fn (PaystackClient $client) => $client->listCustomers(['perPage' => 1]),
        'fetch_customer' => fn (PaystackClient $client) => $client->fetchCustomer($v['code']),
        'update_customer' => fn (PaystackClient $client) => $client->updateCustomer($v['code'], ['first_name' => 'Ada']),
        'set_customer_risk_action' => fn (PaystackClient $client) => $client->setCustomerRiskAction(['customer' => $v['code'], 'risk_action' => 'allow']),
        'validate_customer' => fn (PaystackClient $client) => $client->validateCustomer($v['code'], ['country' => 'NG', 'type' => 'bank_account']),
        'initialize_authorization' => fn (PaystackClient $client) => $client->initializeAuthorization(['email' => 'customer@example.com', 'channel' => 'direct_debit']),
        'verify_authorization' => fn (PaystackClient $client) => $client->verifyAuthorization($v['reference']),
        'deactivate_authorization' => fn (PaystackClient $client) => $client->deactivateAuthorization(['authorization_code' => 'AUTH_code']),
        'initialize_direct_debit' => fn (PaystackClient $client) => $client->initializeDirectDebit($v['id'], ['amount' => 10000]),
        'customer_direct_debit_activation_charge' => fn (PaystackClient $client) => $client->customerDirectDebitActivationCharge($v['id'], ['amount' => 100]),
        'customer_direct_debit_mandate_authorizations' => fn (PaystackClient $client) => $client->customerDirectDebitMandateAuthorizations($v['id'], ['perPage' => 1]),

        'trigger_direct_debit_activation_charge' => fn (PaystackClient $client) => $client->triggerDirectDebitActivationCharge(['authorization_code' => 'AUTH_code']),
        'list_direct_debit_mandate_authorizations' => fn (PaystackClient $client) => $client->listDirectDebitMandateAuthorizations(['perPage' => 1]),

        'create_dedicated_account' => fn (PaystackClient $client) => $client->createDedicatedAccount(['customer' => $v['code']]),
        'list_dedicated_accounts' => fn (PaystackClient $client) => $client->listDedicatedAccounts(['active' => true]),
        'assign_dedicated_account' => fn (PaystackClient $client) => $client->assignDedicatedAccount(['email' => 'customer@example.com']),
        'fetch_dedicated_account' => fn (PaystackClient $client) => $client->fetchDedicatedAccount($v['id']),
        'deactivate_dedicated_account' => fn (PaystackClient $client) => $client->deactivateDedicatedAccount($v['id']),
        'requery_dedicated_account' => fn (PaystackClient $client) => $client->requeryDedicatedAccount(['account_number' => '0000000000']),
        'split_dedicated_account_transaction' => fn (PaystackClient $client) => $client->splitDedicatedAccountTransaction(['customer' => $v['code']]),
        'remove_split_from_dedicated_account' => fn (PaystackClient $client) => $client->removeSplitFromDedicatedAccount(['account_number' => '0000000000']),
        'fetch_dedicated_account_providers' => fn (PaystackClient $client) => $client->fetchDedicatedAccountProviders(),

        'register_apple_pay_domain' => fn (PaystackClient $client) => $client->registerApplePayDomain(['domainName' => 'example.com']),
        'list_apple_pay_domains' => fn (PaystackClient $client) => $client->listApplePayDomains(),
        'unregister_apple_pay_domain' => fn (PaystackClient $client) => $client->unregisterApplePayDomain(['domainName' => 'example.com']),

        'create_plan' => fn (PaystackClient $client) => $client->createPlan(['name' => 'Monthly']),
        'list_plans' => fn (PaystackClient $client) => $client->listPlans(['perPage' => 1]),
        'fetch_plan' => fn (PaystackClient $client) => $client->fetchPlan($v['code']),
        'update_plan' => fn (PaystackClient $client) => $client->updatePlan($v['code'], ['name' => 'Updated']),

        'create_subscription' => fn (PaystackClient $client) => $client->createSubscription(['customer' => $v['code'], 'plan' => $v['code']]),
        'list_subscriptions' => fn (PaystackClient $client) => $client->listSubscriptions(['perPage' => 1]),
        'fetch_subscription' => fn (PaystackClient $client) => $client->fetchSubscription($v['code']),
        'disable_subscription' => fn (PaystackClient $client) => $client->disableSubscription(['code' => $v['code'], 'token' => 'email_token']),
        'enable_subscription' => fn (PaystackClient $client) => $client->enableSubscription(['code' => $v['code'], 'token' => 'email_token']),
        'subscription_management_link' => fn (PaystackClient $client) => $client->subscriptionManagementLink($v['code']),
        'send_subscription_management_email' => fn (PaystackClient $client) => $client->sendSubscriptionManagementEmail($v['code']),

        'create_transfer_recipient' => fn (PaystackClient $client) => $client->createTransferRecipient(['type' => 'nuban']),
        'list_transfer_recipients' => fn (PaystackClient $client) => $client->listTransferRecipients(['perPage' => 1]),
        'bulk_create_transfer_recipients' => fn (PaystackClient $client) => $client->bulkCreateTransferRecipients(['batch' => []]),
        'fetch_transfer_recipient' => fn (PaystackClient $client) => $client->fetchTransferRecipient($v['code']),
        'update_transfer_recipient' => fn (PaystackClient $client) => $client->updateTransferRecipient($v['code'], ['name' => 'Ada']),
        'delete_transfer_recipient' => fn (PaystackClient $client) => $client->deleteTransferRecipient($v['code']),

        'initiate_transfer' => fn (PaystackClient $client) => $client->initiateTransfer(['source' => 'balance']),
        'list_transfers' => fn (PaystackClient $client) => $client->listTransfers(['perPage' => 1]),
        'finalize_transfer' => fn (PaystackClient $client) => $client->finalizeTransfer(['transfer_code' => $v['code'], 'otp' => '123456']),
        'initiate_bulk_transfer' => fn (PaystackClient $client) => $client->initiateBulkTransfer(['source' => 'balance', 'transfers' => []]),
        'fetch_transfer' => fn (PaystackClient $client) => $client->fetchTransfer($v['code']),
        'verify_transfer' => fn (PaystackClient $client) => $client->verifyTransfer($v['reference']),
        'export_transfers' => fn (PaystackClient $client) => $client->exportTransfers(['from' => '2026-04-01']),
        'resend_transfer_otp' => fn (PaystackClient $client) => $client->resendTransferOtp(['transfer_code' => $v['code'], 'reason' => 'resend_otp']),
        'disable_transfer_otp' => fn (PaystackClient $client) => $client->disableTransferOtp(),
        'finalize_disable_transfer_otp' => fn (PaystackClient $client) => $client->finalizeDisableTransferOtp(['otp' => '123456']),
        'enable_transfer_otp' => fn (PaystackClient $client) => $client->enableTransferOtp(),

        'balance' => fn (PaystackClient $client) => $client->balance(),
        'balance_ledger' => fn (PaystackClient $client) => $client->balanceLedger(['currency' => 'NGN']),

        'create_payment_request' => fn (PaystackClient $client) => $client->createPaymentRequest(['customer' => $v['code']]),
        'list_payment_requests' => fn (PaystackClient $client) => $client->listPaymentRequests(['perPage' => 1]),
        'fetch_payment_request' => fn (PaystackClient $client) => $client->fetchPaymentRequest($v['id']),
        'update_payment_request' => fn (PaystackClient $client) => $client->updatePaymentRequest($v['id'], ['description' => 'Updated']),
        'verify_payment_request' => fn (PaystackClient $client) => $client->verifyPaymentRequest($v['id']),
        'notify_payment_request' => fn (PaystackClient $client) => $client->notifyPaymentRequest($v['id']),
        'payment_request_totals' => fn (PaystackClient $client) => $client->paymentRequestTotals(),
        'finalize_payment_request' => fn (PaystackClient $client) => $client->finalizePaymentRequest($v['id']),
        'archive_payment_request' => fn (PaystackClient $client) => $client->archivePaymentRequest($v['id']),

        'create_product' => fn (PaystackClient $client) => $client->createProduct(['name' => 'Product']),
        'list_products' => fn (PaystackClient $client) => $client->listProducts(['perPage' => 1]),
        'fetch_product' => fn (PaystackClient $client) => $client->fetchProduct($v['id']),
        'update_product' => fn (PaystackClient $client) => $client->updateProduct($v['id'], ['name' => 'Updated']),
        'delete_product' => fn (PaystackClient $client) => $client->deleteProduct($v['id']),

        'create_storefront' => fn (PaystackClient $client) => $client->createStorefront(['name' => 'Store']),
        'list_storefronts' => fn (PaystackClient $client) => $client->listStorefronts(['perPage' => 1]),
        'fetch_storefront' => fn (PaystackClient $client) => $client->fetchStorefront($v['id']),
        'update_storefront' => fn (PaystackClient $client) => $client->updateStorefront($v['id'], ['name' => 'Updated']),
        'delete_storefront' => fn (PaystackClient $client) => $client->deleteStorefront($v['id']),
        'verify_storefront' => fn (PaystackClient $client) => $client->verifyStorefront($v['slug']),
        'list_storefront_orders' => fn (PaystackClient $client) => $client->listStorefrontOrders($v['id'], ['perPage' => 1]),
        'add_storefront_products' => fn (PaystackClient $client) => $client->addStorefrontProducts($v['id'], ['products' => []]),
        'list_storefront_products' => fn (PaystackClient $client) => $client->listStorefrontProducts($v['id'], ['perPage' => 1]),
        'publish_storefront' => fn (PaystackClient $client) => $client->publishStorefront($v['id']),
        'duplicate_storefront' => fn (PaystackClient $client) => $client->duplicateStorefront($v['id']),

        'create_order' => fn (PaystackClient $client) => $client->createOrder(['product' => $v['id']]),
        'list_orders' => fn (PaystackClient $client) => $client->listOrders(['perPage' => 1]),
        'fetch_order' => fn (PaystackClient $client) => $client->fetchOrder($v['id']),
        'list_product_orders' => fn (PaystackClient $client) => $client->listProductOrders($v['id'], ['perPage' => 1]),
        'validate_order' => fn (PaystackClient $client) => $client->validateOrder($v['code']),

        'create_page' => fn (PaystackClient $client) => $client->createPage(['name' => 'Page']),
        'list_pages' => fn (PaystackClient $client) => $client->listPages(['perPage' => 1]),
        'fetch_page' => fn (PaystackClient $client) => $client->fetchPage($v['id']),
        'update_page' => fn (PaystackClient $client) => $client->updatePage($v['id'], ['name' => 'Updated']),
        'check_slug_availability' => fn (PaystackClient $client) => $client->checkSlugAvailability($v['slug']),
        'add_products_to_page' => fn (PaystackClient $client) => $client->addProductsToPage($v['id'], ['products' => []]),

        'list_settlements' => fn (PaystackClient $client) => $client->listSettlements(['perPage' => 1]),
        'list_settlement_transactions' => fn (PaystackClient $client) => $client->listSettlementTransactions($v['id'], ['perPage' => 1]),

        'fetch_payment_session_timeout' => fn (PaystackClient $client) => $client->fetchPaymentSessionTimeout(),
        'update_payment_session_timeout' => fn (PaystackClient $client) => $client->updatePaymentSessionTimeout(['timeout' => 30]),

        'create_refund' => fn (PaystackClient $client) => $client->createRefund(['transaction' => $v['reference']]),
        'list_refunds' => fn (PaystackClient $client) => $client->listRefunds(['perPage' => 1]),
        'retry_refund_with_customer_details' => fn (PaystackClient $client) => $client->retryRefundWithCustomerDetails($v['id'], ['customer_note' => 'Retry']),
        'fetch_refund' => fn (PaystackClient $client) => $client->fetchRefund($v['id']),

        'list_disputes' => fn (PaystackClient $client) => $client->listDisputes(['perPage' => 1]),
        'fetch_dispute' => fn (PaystackClient $client) => $client->fetchDispute($v['id']),
        'update_dispute' => fn (PaystackClient $client) => $client->updateDispute($v['id'], ['refund_amount' => 1000]),
        'dispute_upload_url' => fn (PaystackClient $client) => $client->disputeUploadUrl($v['id']),
        'export_disputes' => fn (PaystackClient $client) => $client->exportDisputes(['from' => '2026-04-01']),
        'transaction_disputes' => fn (PaystackClient $client) => $client->transactionDisputes($v['id'], ['perPage' => 1]),
        'resolve_dispute' => fn (PaystackClient $client) => $client->resolveDispute($v['id'], ['resolution' => 'merchant-accepted']),
        'add_dispute_evidence' => fn (PaystackClient $client) => $client->addDisputeEvidence($v['id'], ['customer_email' => 'customer@example.com']),

        'list_banks' => fn (PaystackClient $client) => $client->listBanks(['country' => 'nigeria']),
        'resolve_bank_account' => fn (PaystackClient $client) => $client->resolveBankAccount(['account_number' => '0000000000', 'bank_code' => '044']),
        'validate_bank_account' => fn (PaystackClient $client) => $client->validateBankAccount(['account_number' => '0000000000', 'bank_code' => '044']),
        'resolve_card_bin' => fn (PaystackClient $client) => $client->resolveCardBin($v['bin']),
        'list_countries' => fn (PaystackClient $client) => $client->listCountries(),
        'list_address_verification_states' => fn (PaystackClient $client) => $client->listAddressVerificationStates(['country' => 'NG']),
    ];
}

function paystackExpectedUrl(string $endpoint): string
{
    $path = PaystackClient::ENDPOINTS[$endpoint][1];

    foreach (paystackPathValues() as $key => $value) {
        $path = str_replace('{'.$key.'}', rawurlencode((string) $value), $path);
    }

    return PaystackClient::BASE_URL.$path;
}

it('requires a secret key unless a custom token provider is supplied', function (): void {
    expect(fn () => PaystackClient::make(Http::getFacadeRoot()))
        ->toThrow(ConfigurationException::class, 'PaystackClient requires either secret_key');

    expect(PaystackClient::make(Http::getFacadeRoot(), [], paystackTokenProvider()))
        ->toBeInstanceOf(PaystackClient::class);
});

it('sends authenticated requests with configured headers and request options', function (): void {
    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response(['status' => true], 200),
        'https://api.paystack.co/transaction/verify/*' => Http::response(['status' => true], 200),
    ]);

    $client = paystackClient([
        'secret_key' => 'sk_test_secret',
        'user_agent' => 'laravel-payments/paystack',
    ], paystackTokenProvider('provider_token'));

    expect($client->getAccessToken())->toBe('provider_token')
        ->and($client->getAccessToken(forceRefresh: true))->toBe('provider_token_fresh');

    $client->initializeTransaction(
        ['email' => 'customer@example.com', 'amount' => 10000],
        new RequestOptions(headers: ['X-Request-Id' => 'req_123']),
    );

    $client->verifyTransaction('ref_123', [
        'access_token' => 'request_token',
        'headers' => ['X-Request-Id' => 'req_456'],
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paystack.co/transaction/initialize'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer provider_token')
        && $request->hasHeader('User-Agent', 'laravel-payments/paystack')
        && $request->hasHeader('X-Request-Id', 'req_123'));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paystack.co/transaction/verify/ref_123'
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer request_token')
        && $request->hasHeader('X-Request-Id', 'req_456'));
});

it('exposes raw authorized paystack helpers without rewriting payloads', function (): void {
    Http::fake([
        'https://api.paystack.co/custom/raw' => Http::response(['status' => true, 'method' => 'post'], 200),
        'https://api.paystack.co/custom/raw-get*' => Http::response(['status' => true, 'method' => 'get'], 200),
        'https://api.paystack.co/custom/raw-put' => Http::response(['status' => true, 'method' => 'put'], 200),
        'https://api.paystack.co/custom/raw-delete*' => Http::response(['status' => true, 'method' => 'delete'], 200),
    ]);

    $client = paystackClient(tokenProvider: paystackTokenProvider('raw_token'));

    expect($client->authorizedPost('/custom/raw', ['amount' => 10000])['method'])->toBe('post')
        ->and($client->authorizedGet('/custom/raw-get', ['reference' => 'ref_123'])['method'])->toBe('get')
        ->and($client->authorizedPut('/custom/raw-put', ['amount' => 20000])['method'])->toBe('put')
        ->and($client->authorizedDelete('/custom/raw-delete', ['amount' => 5000], ['reference' => 'ref_456'])['method'])->toBe('delete');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.paystack.co/custom/raw'
        && $request->data()['amount'] === 10000
        && $request->hasHeader('Authorization', 'Bearer raw_token'));
    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.paystack.co/custom/raw-get?reference=ref_123'
        && $request->hasHeader('Authorization', 'Bearer raw_token'));
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://api.paystack.co/custom/raw-put'
        && $request->data()['amount'] === 20000
        && $request->hasHeader('Authorization', 'Bearer raw_token'));
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.paystack.co/custom/raw-delete?reference=ref_456'
        && $request->data()['amount'] === 5000
        && $request->hasHeader('Authorization', 'Bearer raw_token'));
});

it('keeps an explicit User-Agent header over the user_agent fallback', function (): void {
    Http::fake([
        'https://api.paystack.co/balance' => Http::response(['status' => true], 200),
    ]);

    $client = paystackClient([
        'default_headers' => ['User-Agent' => 'explicit/1.0'],
        'user_agent' => 'ignored/1.0',
    ]);

    $client->balance();

    Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', 'explicit/1.0'));
});

it('honors endpoint overrides by path or full endpoint definition', function (): void {
    Http::fake([
        'https://api.paystack.co/custom/initialize' => Http::response(['status' => true], 200),
        'https://api.paystack.co/custom/banks' => Http::response(['status' => true], 200),
    ]);

    $client = paystackClient([
        'endpoints' => [
            'initialize_transaction' => '/custom/initialize',
            'list_banks' => ['method' => 'POST', 'path' => '/custom/banks'],
            'ignored' => '/ignored',
            'list_countries' => '',
        ],
    ]);

    $client->initializeTransaction(['email' => 'customer@example.com', 'amount' => 10000]);
    $client->listBanks();

    $requests = collect(Http::recorded())->map(fn (array $record): array => [
        'method' => $record[0]->method(),
        'url' => $record[0]->url(),
    ])->all();

    expect($requests)->toBe([
        ['method' => 'POST', 'url' => 'https://api.paystack.co/custom/initialize'],
        ['method' => 'POST', 'url' => 'https://api.paystack.co/custom/banks'],
    ]);
});

it('maps every documented Paystack API endpoint to its official method and path', function (): void {
    Http::fake([
        'https://api.paystack.co/*' => Http::response(['status' => true], 200),
    ]);

    $calls = paystackEndpointCalls();
    $client = paystackClient();

    expect(array_keys($calls))->toEqualCanonicalizing(array_keys(PaystackClient::ENDPOINTS));

    foreach ($calls as $endpoint => $call) {
        $call($client);

        $record = collect(Http::recorded())->last();
        $request = $record[0];

        expect($request->method())->toBe(PaystackClient::ENDPOINTS[$endpoint][0])
            ->and(strtok($request->url(), '?'))->toBe(paystackExpectedUrl($endpoint));
    }
});

it('throws ConfigurationException for missing constructor endpoint maps', function (): void {
    $client = new PaystackClient(
        http: new HttpTransport(Http::getFacadeRoot(), PaystackClient::BASE_URL),
        tokens: paystackTokenProvider(),
        endpoints: [],
    );

    expect(fn () => $client->initializeTransaction(['amount' => 100]))
        ->toThrow(ConfigurationException::class, 'Unknown Paystack endpoint [initialize_transaction].');
});

it('authorizes the capitec pay requery with the public key rather than the secret key', function (): void {
    Http::fake([
        'https://api.paystack.co/*' => Http::response(['status' => true], 200),
    ]);

    $configured = paystackClient(['public_key' => 'pk_test_configured']);
    $configured->requeryCapitecPayCharge('ref_123');
    $configured->requeryCapitecPayCharge('ref_123', 'pk_test_explicit');
    $configured->requeryCapitecPayCharge('ref_123', options: new RequestOptions(accessToken: 'pk_test_option'));

    paystackClient()->requeryCapitecPayCharge('ref_123');

    $authorizations = collect(Http::recorded())
        ->map(fn (array $record): string => $record[0]->header('Authorization')[0])
        ->all();

    expect($authorizations)->toBe([
        'Bearer pk_test_configured',
        'Bearer pk_test_explicit',
        'Bearer pk_test_option',
        'Bearer sk_test_default',
    ]);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && strtok($request->url(), '?') === 'https://api.paystack.co/capitec-pay/requery/ref_123');
});

it('detects paystack business failures returned with http 200', function (): void {
    $failure = ['status' => false, 'message' => 'Invalid key'];

    Http::fake([
        'https://api.paystack.co/*' => Http::response($failure, 200),
    ]);

    expect(paystackClient()->listBanks())->toBe($failure)
        ->and(PaystackClient::succeeded($failure))->toBeFalse()
        ->and(PaystackClient::statusMessage($failure))->toBe('Invalid key')
        ->and(PaystackClient::succeeded(['status' => true]))->toBeTrue();

    expect(fn () => paystackClient(['throw_on_business_error' => true])->listBanks())
        ->toThrow(BusinessException::class, 'Invalid key');

    expect(fn () => paystackClient()->authorizedGet('/bank', [], ['throw_on_business_error' => true]))
        ->toThrow(BusinessException::class, 'Invalid key');
});
