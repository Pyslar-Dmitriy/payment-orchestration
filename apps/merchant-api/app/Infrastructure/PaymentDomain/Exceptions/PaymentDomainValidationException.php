<?php

namespace App\Infrastructure\PaymentDomain\Exceptions;

use Illuminate\Http\JsonResponse;

final class PaymentDomainValidationException extends \RuntimeException
{
    const int ERROR_STATUS_CODE = 422;

    const string ERROR_MESSAGE = 'Payment domain validation error.';

    public function __construct(private readonly array $payload)
    {
        parent::__construct(self::ERROR_MESSAGE);
    }

    public function render(): JsonResponse
    {
        return response()->json($this->payload, self::ERROR_STATUS_CODE);
    }
}
