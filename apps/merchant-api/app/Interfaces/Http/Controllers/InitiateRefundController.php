<?php

namespace App\Interfaces\Http\Controllers;

use App\Domain\IdempotencyKey\IdempotencyKey;
use App\Infrastructure\PaymentDomain\PaymentDomainClient;
use App\Interfaces\Http\Requests\InitiateRefundRequest;
use Illuminate\Http\JsonResponse;

final class InitiateRefundController
{
    public function __construct(private readonly PaymentDomainClient $paymentDomainClient) {}

    public function __invoke(InitiateRefundRequest $request): JsonResponse
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

        $result = $this->paymentDomainClient->initiateRefund([
            'payment_id' => $request->validated('payment_id'),
            'merchant_id' => $merchant->id,
            'amount' => $request->validated('amount'),
            'correlation_id' => $correlationId,
        ]);

        if ($result === null) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

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
