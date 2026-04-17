<?php

use App\Http\Middleware\InternalNetworkMiddleware;
use App\Interfaces\Http\Controllers\HealthController;
use App\Interfaces\Http\Controllers\ProviderAuthorizeController;
use App\Interfaces\Http\Controllers\ProviderAvailabilityController;
use App\Interfaces\Http\Controllers\ProviderPaymentStatusController;
use App\Interfaces\Http\Controllers\ProviderRefundController;
use App\Interfaces\Http\Controllers\ProviderRefundStatusController;
use App\Interfaces\Http\Controllers\ProviderRouteController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health']);
Route::get('/ready', [HealthController::class, 'ready']);

Route::prefix('v1/provider')->group(function () {
    Route::post('/authorize', ProviderAuthorizeController::class);
    Route::post('/refund', ProviderRefundController::class);
    Route::post('/route', ProviderRouteController::class);
    Route::get('/payments/{paymentUuid}/status', ProviderPaymentStatusController::class);
    Route::get('/refunds/{refundUuid}/status', ProviderRefundStatusController::class);
});

Route::prefix('internal/providers')->middleware(InternalNetworkMiddleware::class)->group(function () {
    Route::patch('/{key}/availability', ProviderAvailabilityController::class);
});
