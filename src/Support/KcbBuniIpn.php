<?php

namespace NoriaLabs\Payments\Support;

class KcbBuniIpn
{
    public const TYPE_TILL = 'till';

    public const TYPE_ACCOUNT = 'account';

    public const TYPE_VALIDATION = 'validation';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function type(array $payload): ?string
    {
        if (isset($payload['requestPayload']) && is_array($payload['requestPayload'])) {
            return self::TYPE_TILL;
        }

        if (array_key_exists('transactionReference', $payload) || array_key_exists('transactionAmount', $payload)) {
            return self::TYPE_ACCOUNT;
        }

        if (array_key_exists('customerReference', $payload) && array_key_exists('organizationReference', $payload)) {
            return self::TYPE_VALIDATION;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function tillNotificationData(array $payload): array
    {
        $data = $payload['requestPayload']['additionalData']['notificationData'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function tillPrimaryData(array $payload): array
    {
        $data = $payload['requestPayload']['primaryData'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $payload  the inbound till notification
     * @return array<string, mixed>
     */
    public static function tillAcknowledgement(
        array $payload,
        string $transactionId,
        string $statusCode = '0',
        string $statusMessage = 'Success',
    ): array {
        $header = is_array($payload['header'] ?? null) ? $payload['header'] : [];

        return [
            'header' => [
                'messageID' => (string) ($header['messageID'] ?? ''),
                'originatorConversationID' => (string) ($header['originatorConversationID'] ?? ''),
                'statusCode' => $statusCode,
                'statusMessage' => $statusMessage,
            ],
            'responsePayload' => [
                'transactionInfo' => [
                    'transactionId' => $transactionId,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function accountAcknowledgement(
        string $transactionId,
        string $statusCode = '0',
        string $statusMessage = 'Success',
    ): array {
        return [
            'transactionID' => $transactionId,
            'statusCode' => $statusCode,
            'statusMessage' => $statusMessage,
        ];
    }

    /**
     * @param  array<string, mixed>  $bill  optional CustomerName, billAmount, currency, billType, creditAccountIdentifier
     * @return array<string, mixed>
     */
    public static function validationResponse(
        string $transactionId,
        array $bill = [],
        string $statusCode = '0',
        string $statusMessage = 'Success',
    ): array {
        $response = self::accountAcknowledgement($transactionId, $statusCode, $statusMessage);

        foreach (['CustomerName', 'billAmount', 'currency', 'billType', 'creditAccountIdentifier'] as $key) {
            if (array_key_exists($key, $bill) && $bill[$key] !== null) {
                $response[$key] = $bill[$key];
            }
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function rejection(
        array $payload,
        string $statusCode,
        string $statusMessage,
        string $transactionId = '',
    ): array {
        return match (self::type($payload)) {
            self::TYPE_TILL => self::tillAcknowledgement($payload, $transactionId, $statusCode, $statusMessage),
            default => self::accountAcknowledgement($transactionId, $statusCode, $statusMessage),
        };
    }
}
