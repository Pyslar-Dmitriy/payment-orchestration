<?php

namespace App\Interfaces\Http\Controllers;

use App\Application\Exceptions\InvalidWebhookSignatureException;
use App\Application\Exceptions\MissingEventIdException;
use App\Application\IngestWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebhookIntakeController
{
    public function __construct(private readonly IngestWebhook $ingestWebhook) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $providerConfig = config("webhooks.providers.{$provider}");

        if ($providerConfig === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $rawBody = $request->getContent();

        if ($rawBody === '') {
            return response()->json(['message' => 'Payload must not be empty.'], 422);
        }

        $headers = collect($request->headers->all())
            ->map(fn (array $values): string => $values[0])
            ->all();

        $correlationId = $request->header('X-Correlation-ID');

        try {
            $this->ingestWebhook->execute($provider, $providerConfig, $rawBody, $headers, $correlationId);
        } catch (MissingEventIdException) {
            return response()->json(['message' => 'Missing event identifier.'], 422);
        } catch (InvalidWebhookSignatureException) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return response()->json(['status' => 'received']);
    }
}
