<?php

use App\Http\Middleware\InternalServiceMiddleware;
use App\Interfaces\Http\Controllers\InitiatePaymentController;
use App\Interfaces\Http\Controllers\InitiateRefundController;
use App\Interfaces\Http\Controllers\InternalTransitionPaymentStatusController;
use App\Interfaces\Http\Controllers\InternalTransitionRefundStatusController;
use App\Interfaces\Http\Controllers\ShowPaymentController;
use App\Interfaces\Http\Controllers\ShowRefundController;
use App\Interfaces\Http\Controllers\TransitionPaymentStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/payments', InitiatePaymentController::class);
    Route::get('/payments/{id}', ShowPaymentController::class);
    Route::patch('/payments/{id}/status', TransitionPaymentStatusController::class);

    Route::post('/refunds', InitiateRefundController::class);
    Route::get('/refunds/{id}', ShowRefundController::class);
});

// Internal service-to-service routes — protected by X-Internal-Secret.
// Called only by trusted internal services (e.g. payment-orchestrator).
Route::middleware(InternalServiceMiddleware::class)->prefix('internal/v1')->group(function (): void {
    Route::patch('/payments/{id}/status', InternalTransitionPaymentStatusController::class);
    Route::patch('/refunds/{id}/status', InternalTransitionRefundStatusController::class);
});
