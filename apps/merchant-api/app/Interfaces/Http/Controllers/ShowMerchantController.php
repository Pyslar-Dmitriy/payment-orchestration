<?php

namespace App\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowMerchantController
{
    public function __invoke(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');

        return response()->json([
            'merchant_id' => $merchant->id,
            'name' => $merchant->name,
            'email' => $merchant->email,
            'status' => $merchant->status,
            'callback_url' => $merchant->callback_url,
        ]);
    }
}
