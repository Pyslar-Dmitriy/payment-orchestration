<?php

namespace App\Interfaces\Http\Controllers;

use App\Application\Payment\DTO\InitiatePaymentCommand;
use App\Application\Payment\InitiatePayment;
use App\Interfaces\Http\Requests\InitiatePaymentRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class InitiatePaymentController
{
    public function __construct(private readonly InitiatePayment $initiatePayment) {}

    public function __invoke(InitiatePaymentRequest $request): JsonResponse
    {
        $command = new InitiatePaymentCommand(
            merchantId: $request->validated('merchant_id'),
            amount: $request->validated('amount'),
            currency: $request->validated('currency'),
            externalReference: $request->validated('external_reference'),
            idempotencyKey: $request->validated('idempotency_key'),
            providerId: $request->validated('provider_id'),
            customerReference: $request->validated('customer_reference'),
            paymentMethodReference: $request->validated('payment_method_reference'),
            metadata: $request->validated('metadata'),
            correlationId: $request->validated('correlation_id'),
        );

        $result = $this->initiatePayment->execute($command);

        return response()->json($result, $result->isCreated() ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}