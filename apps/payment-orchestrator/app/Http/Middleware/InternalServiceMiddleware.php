<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates the X-Internal-Secret header for service-to-service routes.
 * Rejects requests that do not carry the correct shared secret with 401.
 */
final class InternalServiceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.internal.secret');

        if (! $secret || $request->header('X-Internal-Secret') !== $secret) {
            return response()->json(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
