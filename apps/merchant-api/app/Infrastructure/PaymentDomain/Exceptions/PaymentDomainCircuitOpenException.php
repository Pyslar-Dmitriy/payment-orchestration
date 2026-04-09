<?php

namespace App\Infrastructure\PaymentDomain\Exceptions;

use Illuminate\Http\JsonResponse;

final class PaymentDomainCircuitOpenException extends \RuntimeException
{
    const string ERROR_CODE = 'CIRCUIT_OPEN';

    const int ERROR_STATUS_CODE = 503;

    const string ERROR_MESSAGE = 'Payment service temporarily unavailable.';

    public function render(): JsonResponse
    {
        return response()->json([
            'error_code' => self::ERROR_CODE,
            'message' => self::ERROR_MESSAGE,
        ], self::ERROR_STATUS_CODE);
    }
}
