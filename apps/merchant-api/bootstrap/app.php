<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Interfaces\Http\Controllers\HealthController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // Health probes: no prefix, no session/web middleware
            Route::middleware([])->group(function (): void {
                Route::get('/health', [HealthController::class, 'health']);
                Route::get('/ready', [HealthController::class, 'ready']);
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CorrelationIdMiddleware::class);
        $middleware->alias(['auth.api' => AuthenticateApiKey::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $e, Request $request): JsonResponse {
            $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            Log::warning('Rate limit exceeded.', [
                'method' => $request->method(),
                'path' => $request->path(),
                'retry_after' => $retryAfter,
            ]);

            return response()->json([
                'message' => 'Too many requests.',
                'retry_after' => $retryAfter,
            ], 429);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request): JsonResponse {
            return response()->json(['message' => 'Not found.'], 404);
        });
    })->create();
