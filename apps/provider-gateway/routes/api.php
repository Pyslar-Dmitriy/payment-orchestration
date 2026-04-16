<?php

use App\Interfaces\Http\Controllers\HealthController;
use App\Interfaces\Http\Controllers\ProviderAuthorizeController;
use App\Interfaces\Http\Controllers\ProviderPaymentStatusController;
use App\Interfaces\Http\Controllers\ProviderRefundController;
use App\Interfaces\Http\Controllers\ProviderRefundStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health']);
Route::get('/ready', [HealthController::class, 'ready']);

Route::prefix('v1/provider')->group(function () {
    Route::post('/authorize', ProviderAuthorizeController::class);
    Route::post('/refund', ProviderRefundController::class);
    Route::get('/payments/{paymentUuid}/status', ProviderPaymentStatusController::class);
    Route::get('/refunds/{refundUuid}/status', ProviderRefundStatusController::class);
});
