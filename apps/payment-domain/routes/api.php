<?php

use App\Interfaces\Http\Controllers\InitiatePaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/payments', InitiatePaymentController::class);
});
