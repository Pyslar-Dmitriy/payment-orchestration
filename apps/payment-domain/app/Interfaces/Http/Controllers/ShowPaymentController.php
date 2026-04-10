<?php

namespace App\Interfaces\Http\Controllers;

use App\Application\Payment\GetPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShowPaymentController
{
    public function __construct(private readonly GetPayment $getPayment) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $merchantId = (string) $request->query('merchant_id', '');

        $result = $this->getPayment->execute($id, $merchantId);

        if ($result === null) {
            return response()->json(['message' => 'Payment not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json($result, Response::HTTP_OK);
    }
}
