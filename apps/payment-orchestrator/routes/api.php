<?php

use App\Interfaces\Http\Controllers\StartPaymentWorkflowController;
use App\Interfaces\Http\Controllers\StartRefundWorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'payment-orchestrator']));

Route::post('/workflows/payments', StartPaymentWorkflowController::class);
Route::post('/workflows/refunds', StartRefundWorkflowController::class);
