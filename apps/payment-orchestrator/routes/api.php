<?php

use App\Http\Middleware\InternalServiceMiddleware;
use App\Interfaces\Http\Controllers\SignalPaymentWorkflowController;
use App\Interfaces\Http\Controllers\SignalRefundWorkflowController;
use App\Interfaces\Http\Controllers\StartPaymentWorkflowController;
use App\Interfaces\Http\Controllers\StartRefundWorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'payment-orchestrator']));

Route::post('/workflows/payments', StartPaymentWorkflowController::class);
Route::post('/workflows/refunds', StartRefundWorkflowController::class);

Route::middleware(InternalServiceMiddleware::class)->group(function (): void {
    Route::post('/signals/payments/{workflowId}', SignalPaymentWorkflowController::class)
        ->whereUuid('workflowId');
    Route::post('/signals/refunds/{workflowId}', SignalRefundWorkflowController::class)
        ->whereUuid('workflowId');
});
