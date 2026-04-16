<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Application\Provider\QueryPaymentStatusHandler;
use App\Domain\Provider\Exception\ProviderNotFoundException;
use App\Domain\Provider\Exception\ProviderTransientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProviderPaymentStatusController
{
    public function __construct(private readonly QueryPaymentStatusHandler $handler) {}

    public function __invoke(Request $request, string $paymentUuid): JsonResponse
    {
        $providerKey = $request->query('provider_key', '');
        $correlationId = $request->query('correlation_id', '');
        $providerReference = $request->query('provider_reference') ?: null;

        if (! $providerKey || ! $correlationId) {
            return response()->json(['message' => 'The provider_key and correlation_id query parameters are required.'], 422);
        }

        try {
            $result = $this->handler->handle(
                paymentUuid: $paymentUuid,
                providerKey: $providerKey,
                correlationId: $correlationId,
                providerReference: $providerReference,
            );
        } catch (ProviderNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ProviderTransientException $e) {
            return response()->json(['message' => 'Provider temporarily unavailable.'], 503);
        }

        return response()->json($result, 200);
    }
}
