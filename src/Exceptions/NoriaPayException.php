<?php

namespace Noria\Payments\Exceptions;

class NoriaPayException extends ApiException
{
    public function __construct(
        string $message,
        int $statusCode,
        mixed $responseBody,
        public readonly string $errorCode,
        public readonly ?string $transactionId,
    ) {
        parent::__construct($message, $statusCode, $responseBody);
    }

    public static function from(ApiException $exception): self
    {
        $body = is_array($exception->responseBody) ? $exception->responseBody : [];
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        $transactionId = $details['transaction_id'] ?? null;

        return new self(
            message: is_string($error['message'] ?? null) ? $error['message'] : $exception->getMessage(),
            statusCode: $exception->statusCode,
            responseBody: $exception->responseBody,
            errorCode: is_string($error['code'] ?? null) ? $error['code'] : 'unknown',
            transactionId: is_string($transactionId) ? $transactionId : null,
        );
    }

    public function outcomeUnknown(): bool
    {
        return $this->errorCode === 'outcome_unknown' || $this->statusCode >= 500 || ($this->errorCode === 'conflict' && $this->transactionId !== null);
    }
}
