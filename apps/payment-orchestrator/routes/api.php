<?php

use App\Interfaces\Http\Controllers\StartPaymentWorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'payment-orchestrator']));

Route::post('/workflows/payments', StartPaymentWorkflowController::class);
