<?php

namespace App\Infrastructure\PaymentDomain\Exceptions;

use Illuminate\Http\JsonResponse;

final class PaymentDomainUnavailableException extends \RuntimeException
{
    const string ERROR_CODE = 'UPSTREAM_ERROR';

    const int ERROR_STATUS_CODE = 503;

    const string ERROR_MESSAGE = 'Payment service error.';

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => self::ERROR_MESSAGE,
            'error_code' => self::ERROR_CODE,
        ], self::ERROR_STATUS_CODE);
    }
}
