<?php

use Illuminate\Support\Facades\Route;

// API routes for the payment-orchestrator service will be added in TASK-061+.
Route::get('/', fn () => response()->json(['service' => 'payment-orchestrator']));
