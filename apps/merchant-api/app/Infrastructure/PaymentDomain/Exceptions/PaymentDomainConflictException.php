<?php

namespace App\Infrastructure\PaymentDomain\Exceptions;

use Illuminate\Http\JsonResponse;

final class PaymentDomainConflictException extends \RuntimeException
{
    const string ERROR_CODE = 'CONFLICT';

    const int ERROR_STATUS_CODE = 409;

    const string ERROR_MESSAGE = 'Payment domain conflict.';

    public function __construct(private readonly array $payload)
    {
        parent::__construct(self::ERROR_MESSAGE);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error_code' => self::ERROR_CODE,
            'message' => self::ERROR_MESSAGE,
        ], self::ERROR_STATUS_CODE);
    }
}
