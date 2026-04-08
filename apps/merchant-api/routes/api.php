<?php

use App\Interfaces\Http\Controllers\CreateMerchantController;
use App\Interfaces\Http\Controllers\InitiatePaymentController;
use App\Interfaces\Http\Controllers\InitiateRefundController;
use App\Interfaces\Http\Controllers\RotateApiKeyController;
use App\Interfaces\Http\Controllers\ShowMerchantController;
use App\Interfaces\Http\Controllers\ShowPaymentController;
use App\Interfaces\Http\Controllers\ShowRefundController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| v1 API
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function (): void {

    /*
    | Merchant onboarding (no auth — bootstrap / internal use)
    */
    Route::post('/merchants', CreateMerchantController::class);

    /*
    | Authenticated merchant routes
    */
    Route::middleware('auth.api')->group(function (): void {
        Route::get('/merchants/me', ShowMerchantController::class);

        Route::post('/api-keys/rotate', RotateApiKeyController::class);

        Route::post('/payments', InitiatePaymentController::class);
        Route::get('/payments/{id}', ShowPaymentController::class);

        Route::post('/refunds', InitiateRefundController::class);
        Route::get('/refunds/{id}', ShowRefundController::class);
    });
});
