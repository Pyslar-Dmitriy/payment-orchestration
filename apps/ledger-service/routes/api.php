<?php

use App\Interfaces\Http\Controllers\CapturePostingController;
use App\Interfaces\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health']);
Route::get('/ready', [HealthController::class, 'ready']);

Route::post('/postings/capture', [CapturePostingController::class, 'store']);
