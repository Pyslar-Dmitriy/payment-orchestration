<?php

namespace App\Interfaces\Http\Controllers;

use App\Application\Payment\InitiatePayment;
use App\Interfaces\Http\Requests\InitiatePaymentRequest;
use Illuminate\Http\JsonResponse;

final class InitiatePaymentController
{
    public function __construct(private readonly InitiatePayment $initiatePayment) {}

    public function __invoke(InitiatePaymentRequest $request): JsonResponse
    {
        $result = $this->initiatePayment->execute($request->validated());

        $created = $result['created'];
        unset($result['created']);

        return response()->json($result, $created ? 201 : 200);
    }
}