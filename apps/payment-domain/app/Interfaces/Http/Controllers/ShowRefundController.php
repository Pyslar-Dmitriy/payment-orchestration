<?php

namespace App\Interfaces\Http\Controllers;

use App\Application\Refund\GetRefund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShowRefundController
{
    public function __construct(private readonly GetRefund $getRefund) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $merchantId = (string) $request->query('merchant_id', '');

        $result = $this->getRefund->execute($id, $merchantId);

        if ($result === null) {
            return response()->json(['message' => 'Refund not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json($result, Response::HTTP_OK);
    }
}