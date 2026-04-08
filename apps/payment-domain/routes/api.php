<?php

use App\Interfaces\Http\Controllers\InitiatePaymentController;
use App\Interfaces\Http\Controllers\InitiateRefundController;
use App\Interfaces\Http\Controllers\ShowPaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/payments', InitiatePaymentController::class);
    Route::get('/payments/{id}', ShowPaymentController::class);

    Route::post('/refunds', InitiateRefundController::class);
});
