<?php

namespace App\Http\Middleware;

use App\Domain\Merchant\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractBearerToken($request);

        if ($token === null) {
            return $this->unauthorized('Missing API key.');
        }

        $apiKey = ApiKey::findByPlaintext($token);

        if ($apiKey === null) {
            return $this->unauthorized('Invalid or expired API key.');
        }

        $merchant = $apiKey->merchant;

        // Bind merchant context — controllers read via $request->attributes->get('merchant')
        $request->attributes->set('merchant', $merchant);
        $request->attributes->set('api_key', $apiKey);

        // Share merchant_id with all downstream log calls; never log the key or its suffix
        Log::shareContext(['merchant_id' => $merchant->id]);

        // Record last usage without touching updated_at
        ApiKey::withoutTimestamps(static fn () => $apiKey->update(['last_used_at' => now()]));

        return $next($request);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        return $token !== '' ? $token : null;
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['message' => $message], 401);
    }
}
