<?php

namespace App\Interfaces\Http\Controllers;

use App\Infrastructure\Temporal\TemporalPinger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController
{
    public function __construct(private readonly TemporalPinger $temporalPinger) {}

    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            return response()->json(['status' => 'error', 'message' => 'Database unavailable.'], 503);
        }

        if (! $this->temporalPinger->isReachable()) {
            return response()->json(['status' => 'error', 'message' => 'Temporal unavailable.'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
