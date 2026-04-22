<?php

use App\Http\Middleware\InternalServiceMiddleware;
use App\Interfaces\Http\Controllers\DispatchCallbackController;
use App\Interfaces\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health']);
Route::get('/ready', [HealthController::class, 'ready']);

Route::middleware(InternalServiceMiddleware::class)->prefix('v1')->group(function (): void {
    Route::post('/callbacks/dispatch', DispatchCallbackController::class);
});
