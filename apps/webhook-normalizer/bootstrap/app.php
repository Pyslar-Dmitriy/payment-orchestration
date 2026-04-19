<?php

use App\Http\Middleware\CorrelationIdMiddleware;
use App\Interfaces\Console\ConsumeRawWebhookCommand;
use App\Interfaces\Console\PublishOutboxEventsCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withCommands([
        ConsumeRawWebhookCommand::class,
        PublishOutboxEventsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CorrelationIdMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (NotFoundHttpException $e, Request $request): JsonResponse {
            return response()->json(['message' => 'Not found.'], 404);
        });
    })->create();
