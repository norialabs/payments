<?php

use Noria\Payments\Support\KcbBuniIpn;

function kcbBuniTillNotification(): array
{
    return [
        'header' => [
            'messageID' => 'MSG-1',
            'originatorConversationID' => 'OCID-1',
            'channelCode' => 'VOOMA',
            'timeStamp' => '20260818120000',
        ],
        'requestPayload' => [
            'primaryData' => [
                'businessKey' => '522533',
                'businessKeyType' => 'TillNumber',
            ],
            'additionalData' => [
                'notificationData' => [
                    'businessKey' => '522533',
                    'businessKeyType' => 'TillNumber',
                    'debitMSISDN' => '254722000000',
                    'transactionAmt' => '1000',
                    'transactionID' => 'FT000262556',
                    'currency' => 'KES',
                ],
            ],
        ],
    ];
}

function kcbBuniAccountNotification(): array
{
    return [
        'transactionReference' => 'FT000262556',
        'requestId' => 'REQ-1',
        'channelCode' => 'MOBILE',
        'timestamp' => '20260818120000',
        'transactionAmount' => '1000',
        'currency' => 'KES',
        'customerReference' => 'ACC-1',
        'customerName' => 'JOHN DOE',
        'customerMobileNumber' => '254722000000',
        'creditAccountIdentifier' => '1234567890',
    ];
}

it('identifies each documented ipn contract', function (): void {
    expect(KcbBuniIpn::type(kcbBuniTillNotification()))->toBe(KcbBuniIpn::TYPE_TILL)
        ->and(KcbBuniIpn::type(kcbBuniAccountNotification()))->toBe(KcbBuniIpn::TYPE_ACCOUNT)
        ->and(KcbBuniIpn::type([
            'requestId' => 'REQ-1',
            'customerReference' => 'ACC-1',
            'organizationReference' => 'ORG-1',
        ]))->toBe(KcbBuniIpn::TYPE_VALIDATION)
        ->and(KcbBuniIpn::type(['something' => 'else']))->toBeNull();
});

it('reads the nested till notification data', function (): void {
    $payload = kcbBuniTillNotification();

    expect(KcbBuniIpn::tillNotificationData($payload)['transactionID'])->toBe('FT000262556')
        ->and(KcbBuniIpn::tillNotificationData($payload)['transactionAmt'])->toBe('1000')
        ->and(KcbBuniIpn::tillPrimaryData($payload)['businessKey'])->toBe('522533')
        ->and(KcbBuniIpn::tillNotificationData([]))->toBe([])
        ->and(KcbBuniIpn::tillPrimaryData(['requestPayload' => 'not-an-array']))->toBe([]);
});

it('builds the documented till acknowledgement echoing the inbound header', function (): void {
    expect(KcbBuniIpn::tillAcknowledgement(kcbBuniTillNotification(), 'LOCAL-1'))->toBe([
        'header' => [
            'messageID' => 'MSG-1',
            'originatorConversationID' => 'OCID-1',
            'statusCode' => '0',
            'statusMessage' => 'Success',
        ],
        'responsePayload' => [
            'transactionInfo' => [
                'transactionId' => 'LOCAL-1',
            ],
        ],
    ]);
});

it('builds the documented account acknowledgement and validation response', function (): void {
    expect(KcbBuniIpn::accountAcknowledgement('LOCAL-1'))->toBe([
        'transactionID' => 'LOCAL-1',
        'statusCode' => '0',
        'statusMessage' => 'Success',
    ]);

    expect(KcbBuniIpn::validationResponse('LOCAL-1', [
        'CustomerName' => 'JOHN DOE',
        'billAmount' => '1500.00',
        'currency' => 'KES',
        'billType' => 'PREPAID',
        'creditAccountIdentifier' => '1234567890',
        'ignored' => 'dropped',
    ]))->toBe([
        'transactionID' => 'LOCAL-1',
        'statusCode' => '0',
        'statusMessage' => 'Success',
        'CustomerName' => 'JOHN DOE',
        'billAmount' => '1500.00',
        'currency' => 'KES',
        'billType' => 'PREPAID',
        'creditAccountIdentifier' => '1234567890',
    ]);
});

it('shapes a rejection to match the inbound contract', function (): void {
    $till = KcbBuniIpn::rejection(kcbBuniTillNotification(), '1', 'Unknown till');

    expect($till['header']['statusCode'])->toBe('1')
        ->and($till['header']['statusMessage'])->toBe('Unknown till')
        ->and($till['header']['messageID'])->toBe('MSG-1');

    expect(KcbBuniIpn::rejection(kcbBuniAccountNotification(), '1', 'Unknown account'))->toBe([
        'transactionID' => '',
        'statusCode' => '1',
        'statusMessage' => 'Unknown account',
    ]);
});
