<?php

namespace App\Interfaces\Http\Controllers;

use App\Domain\IdempotencyKey\IdempotencyKey;
use App\Infrastructure\PaymentDomain\PaymentDomainClient;
use App\Interfaces\Http\Requests\InitiatePaymentRequest;
use Illuminate\Http\JsonResponse;

final class InitiatePaymentController
{
    public function __construct(private readonly PaymentDomainClient $paymentDomainClient) {}

    public function __invoke(InitiatePaymentRequest $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $correlationId = $request->header('X-Correlation-ID');
        $idempotencyKeyValue = $request->header('Idempotency-Key');

        if ($idempotencyKeyValue !== null) {
            $existing = IdempotencyKey::where('merchant_id', $merchant->id)
                ->where('idempotency_key', $idempotencyKeyValue)
                ->first();

            if ($existing !== null) {
                return response()->json($existing->response_body, $existing->status_code);
            }
        }

        $result = $this->paymentDomainClient->initiatePayment([
            'merchant_id' => $merchant->id,
            'amount' => $request->validated('amount'),
            'currency' => $request->validated('currency'),
            'external_reference' => $request->validated('external_order_id'),
            'customer_reference' => $request->validated('customer_reference'),
            'payment_method_reference' => $request->validated('payment_method_token'),
            'metadata' => $request->validated('metadata'),
            'correlation_id' => $correlationId,
        ]);

        $responseBody = array_merge($result, ['correlation_id' => $correlationId]);

        if ($idempotencyKeyValue !== null) {
            IdempotencyKey::create([
                'merchant_id' => $merchant->id,
                'idempotency_key' => $idempotencyKeyValue,
                'status_code' => 201,
                'response_body' => $responseBody,
            ]);
        }

        return response()->json($responseBody, 201);
    }
}
