<?php

namespace Noria\Payments\Support;

/**
 * One reading of a Noria Pay payment, whether it arrived as a webhook or was fetched with GET /v1/transactions/{id}.
 */
final class NoriaPayEvent
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const REVERSED = 'reversed';

    public const RECEIPT_UPDATED = 'receipt_updated';

    private const SUBJECTS = ['collection' => 'payment', 'payout' => 'payout', 'refund' => 'refund'];

    private const OUTCOMES = ['pending' => 'pending', 'processing' => 'processing', 'unknown' => 'outcome_unknown'];

    private const CHANNEL_NETWORKS = ['mpesa' => 'safaricom', 'airtel' => 'airtel', 'tkash' => 'telkom'];

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private readonly ?string $id,
        private readonly string $type,
        private readonly int $sequence,
        private readonly array $data,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the signed webhook body, decoded
     */
    public static function fromWebhook(array $payload): self
    {
        $data = Setting::map($payload['data'] ?? null);

        return new self(
            id: Setting::string($payload['id'] ?? null) ?: null,
            type: Setting::string($payload['type'] ?? null),
            sequence: Setting::int($payload['sequence'] ?? null),
            data: $data,
        );
    }

    /**
     * @param  array<string, mixed>  $transaction  a transaction from the API
     */
    public static function fromTransaction(array $transaction): self
    {
        $status = Setting::string($transaction['status'] ?? null);
        $direction = Setting::string($transaction['direction'] ?? null, 'collection');
        $requested = Setting::int($transaction['amount_minor'] ?? null);
        $settled = isset($transaction['settled_minor']) ? Setting::int($transaction['settled_minor']) : null;
        $channel = Setting::string($transaction['channel'] ?? null);

        return new self(
            id: null,
            type: (self::SUBJECTS[$direction] ?? 'payment').'.'.(self::OUTCOMES[$status] ?? $status),
            sequence: 0,
            data: [
                'transaction_id' => $transaction['id'] ?? null,
                'reference' => $transaction['reference'] ?? null,
                'source' => $transaction['source'] ?? null,
                'workspace_id' => $transaction['workspace_id'] ?? null,
                'application_id' => $transaction['application_id'] ?? null,
                'direction' => $direction,
                'mode' => $transaction['mode'] ?? null,
                'status' => $status,
                'final' => (bool) ($transaction['final'] ?? in_array($status, [self::SUCCEEDED, self::FAILED, self::CANCELLED, self::EXPIRED, self::REVERSED], true)),
                'amount' => [
                    'currency' => $transaction['currency'] ?? null,
                    'requested_minor' => $requested,
                    'settled_minor' => $settled,
                    'fee_minor' => $transaction['fee_minor'] ?? null,
                    'underpaid' => $status === self::SUCCEEDED && $settled !== null && $settled < $requested,
                ],
                'rail' => ['channel' => $channel, 'network' => $transaction['network'] ?? self::CHANNEL_NETWORKS[$channel] ?? null, 'provider' => $transaction['provider'] ?? null],
                'receipts' => ['network' => $transaction['network_receipt'] ?? null, 'provider' => $transaction['provider_receipt'] ?? null, 'provider_ref' => $transaction['provider_ref'] ?? null],
                'payer' => ['name' => $transaction['payer_name'] ?? null, 'phone' => $transaction['payer_phone'] ?? null, 'email' => $transaction['payer_email'] ?? null],
                'recipient' => ['name' => $transaction['recipient_name'] ?? null, 'phone' => $transaction['recipient_phone'] ?? null, 'account' => $transaction['recipient_account'] ?? null, 'confirmed_name' => null],
                'failure' => $transaction['failure'] ?? null,
                'times' => ['created_at' => $transaction['created_at'] ?? null, 'settled_at' => $transaction['settled_at'] ?? null, 'expires_at' => $transaction['expires_at'] ?? null, 'event_at' => null],
                'description' => $transaction['description'] ?? null,
                'metadata' => Setting::map($transaction['metadata'] ?? null),
                'links' => ['parent_transaction_id' => $transaction['parent_transaction_id'] ?? null, 'payment_link_id' => $transaction['payment_link_id'] ?? null],
                'next_action' => $transaction['next_action'] ?? null,
                'provider_payload' => $transaction['provider_payload'] ?? null,
            ],
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    /** payment.succeeded, payout.failed, payment.receipt_updated, … */
    public function type(): string
    {
        return $this->type;
    }

    /** payment, payout or refund */
    public function subject(): string
    {
        return explode('.', $this->type, 2)[0];
    }

    /** succeeded, failed, cancelled, expired, reversed, pending, processing, outcome_unknown or receipt_updated */
    public function outcome(): string
    {
        return explode('.', $this->type, 2)[1] ?? '';
    }

    /** Per transaction. Ignore an event older than one already applied; zero means a rebuilt snapshot or a fetched transaction. */
    public function sequence(): int
    {
        return $this->sequence;
    }

    public function transactionId(): string
    {
        return $this->string('transaction_id');
    }

    public function reference(): string
    {
        return $this->string('reference');
    }

    public function direction(): string
    {
        return $this->string('direction', 'collection');
    }

    public function mode(): string
    {
        return $this->string('mode');
    }

    public function applicationId(): ?string
    {
        return $this->string('application_id') ?: null;
    }

    public function applicationReference(): ?string
    {
        return $this->string('application_reference') ?: null;
    }

    public function status(): string
    {
        return $this->string('status');
    }

    public function previousStatus(): ?string
    {
        return $this->string('previous_status') ?: null;
    }

    public function isFinal(): bool
    {
        return (bool) ($this->data['final'] ?? false);
    }

    public function isCollection(): bool
    {
        return $this->direction() === 'collection';
    }

    public function isPayout(): bool
    {
        return $this->direction() === 'payout';
    }

    public function isRefund(): bool
    {
        return $this->direction() === 'refund';
    }

    public function isSucceeded(): bool
    {
        return $this->status() === self::SUCCEEDED;
    }

    /** Failed, cancelled or expired: nothing settled, and the status will not change by itself. */
    public function isFailed(): bool
    {
        return in_array($this->status(), [self::FAILED, self::CANCELLED, self::EXPIRED], true);
    }

    public function isReversed(): bool
    {
        return $this->status() === self::REVERSED;
    }

    /** A later receipt for a payment already settled. Update the receipt; never credit it again. */
    public function isReceiptUpdate(): bool
    {
        return $this->outcome() === self::RECEIPT_UPDATED;
    }

    public function currency(): string
    {
        return Setting::string($this->amount()['currency'] ?? null);
    }

    public function requestedMinor(): int
    {
        return Setting::int($this->amount()['requested_minor'] ?? null);
    }

    public function settledMinor(): ?int
    {
        $settled = $this->amount()['settled_minor'] ?? null;

        return $settled === null ? null : Setting::int($settled);
    }

    public function feeMinor(): ?int
    {
        $fee = $this->amount()['fee_minor'] ?? null;

        return $fee === null ? null : Setting::int($fee);
    }

    public function isUnderpaid(): bool
    {
        return (bool) ($this->amount()['underpaid'] ?? false);
    }

    public function channel(): string
    {
        return Setting::string($this->rail()['channel'] ?? null);
    }

    /** safaricom, airtel or telkom for mobile money; null otherwise */
    public function network(): ?string
    {
        return Setting::string($this->rail()['network'] ?? null) ?: null;
    }

    public function provider(): string
    {
        return Setting::string($this->rail()['provider'] ?? null);
    }

    /** The code on the payer's M-Pesa, Airtel Money or T-Kash message. */
    public function networkReceipt(): ?string
    {
        return Setting::string($this->receipts()['network'] ?? null) ?: null;
    }

    /** The gateway's own code, such as SasaPay's SP… reference. */
    public function providerReceipt(): ?string
    {
        return Setting::string($this->receipts()['provider'] ?? null) ?: null;
    }

    public function providerRef(): ?string
    {
        return Setting::string($this->receipts()['provider_ref'] ?? null) ?: null;
    }

    /** The code to show and match statements by: the network's when there is one, otherwise the gateway's. */
    public function receipt(): ?string
    {
        return $this->networkReceipt() ?? $this->providerReceipt();
    }

    /** One code for every provider: cancelled_by_payer, insufficient_funds, configuration_error, underpaid, … */
    public function failureCode(): ?string
    {
        return Setting::string($this->failure()['code'] ?? null) ?: null;
    }

    /** Safe to show the payer as it is. */
    public function failureMessage(): ?string
    {
        return Setting::string($this->failure()['message'] ?? null) ?: null;
    }

    /** Whether asking the payer to try again makes sense. */
    public function isRetryable(): bool
    {
        return (bool) ($this->failure()['retryable'] ?? false);
    }

    public function providerFailureCode(): ?string
    {
        return Setting::string($this->failure()['provider_code'] ?? null) ?: null;
    }

    public function providerFailureMessage(): ?string
    {
        return Setting::string($this->failure()['provider_message'] ?? null) ?: null;
    }

    /**
     * @return array{name: ?string, phone: ?string, email: ?string}
     */
    public function payer(): array
    {
        $payer = Setting::map($this->data['payer'] ?? null);

        return ['name' => self::nullable($payer['name'] ?? null), 'phone' => self::nullable($payer['phone'] ?? null), 'email' => self::nullable($payer['email'] ?? null)];
    }

    /**
     * @return array{name: ?string, phone: ?string, account: ?string, confirmed_name: ?string}
     */
    public function recipient(): array
    {
        $recipient = Setting::map($this->data['recipient'] ?? null);

        return [
            'name' => self::nullable($recipient['name'] ?? null),
            'phone' => self::nullable($recipient['phone'] ?? null),
            'account' => self::nullable($recipient['account'] ?? null),
            'confirmed_name' => self::nullable($recipient['confirmed_name'] ?? null),
        ];
    }

    public function settledAt(): ?string
    {
        return self::nullable(Setting::map($this->data['times'] ?? null)['settled_at'] ?? null);
    }

    public function createdAt(): ?string
    {
        return self::nullable(Setting::map($this->data['times'] ?? null)['created_at'] ?? null);
    }

    public function description(): ?string
    {
        return $this->string('description') ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return Setting::map($this->data['metadata'] ?? null);
    }

    public function meta(string $key): ?string
    {
        return self::nullable($this->metadata()[$key] ?? null);
    }

    public function parentTransactionId(): ?string
    {
        return self::nullable(Setting::map($this->data['links'] ?? null)['parent_transaction_id'] ?? null);
    }

    public function callbackId(): ?string
    {
        return $this->string('callback_id') ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(): array
    {
        return Setting::map($this->data['detail'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    private function amount(): array
    {
        return Setting::map($this->data['amount'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function rail(): array
    {
        return Setting::map($this->data['rail'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function receipts(): array
    {
        return Setting::map($this->data['receipts'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(): array
    {
        return Setting::map($this->data['failure'] ?? null);
    }

    private function string(string $key, string $fallback = ''): string
    {
        return Setting::string($this->data[$key] ?? null, $fallback);
    }

    private static function nullable(mixed $value): ?string
    {
        $text = Setting::string($value);

        return $text === '' ? null : $text;
    }
}
