<?php

use App\Interfaces\Http\Controllers\HealthController;
use App\Interfaces\Http\Controllers\WebhookIntakeController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health']);
Route::get('/ready', [HealthController::class, 'ready']);

Route::post('/webhooks/{provider}', WebhookIntakeController::class);
