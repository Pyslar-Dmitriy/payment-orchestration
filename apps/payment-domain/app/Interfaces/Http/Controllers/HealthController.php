<?php

namespace App\Interfaces\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HealthController
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok'], Response::HTTP_OK);
    }

    public function ready(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            return response()->json(['status' => 'error', 'message' => 'Database unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json(['status' => 'ok'], Response::HTTP_OK);
    }
}
