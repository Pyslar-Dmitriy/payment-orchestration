<?php

namespace App\Interfaces\Http\Controllers;

use App\Infrastructure\PaymentDomain\PaymentDomainClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowPaymentController
{
    public function __construct(private readonly PaymentDomainClient $paymentDomainClient) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $correlationId = $request->header('X-Correlation-ID');

        $result = $this->paymentDomainClient->getPayment($id, $merchant->id, $correlationId);

        if ($result === null) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        return response()->json(array_merge($result, ['correlation_id' => $correlationId]));
    }
}
