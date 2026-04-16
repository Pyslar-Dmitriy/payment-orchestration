<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Application\Provider\AuthorizeAndCaptureHandler;
use App\Domain\Provider\Exception\ProviderHardFailureException;
use App\Domain\Provider\Exception\ProviderNotFoundException;
use App\Domain\Provider\Exception\ProviderTransientException;
use App\Interfaces\Http\Requests\ProviderAuthorizeRequest;
use Illuminate\Http\JsonResponse;

final class ProviderAuthorizeController
{
    public function __construct(private readonly AuthorizeAndCaptureHandler $handler) {}

    public function __invoke(ProviderAuthorizeRequest $request): JsonResponse
    {
        try {
            $result = $this->handler->handle(
                paymentUuid: $request->input('payment_uuid'),
                providerKey: $request->input('provider_key'),
                correlationId: $request->input('correlation_id'),
                amount: (int) $request->input('amount', 0),
                currency: (string) $request->input('currency', ''),
                country: (string) $request->input('country', ''),
            );
        } catch (ProviderNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ProviderHardFailureException $e) {
            return response()->json([
                'message' => 'Provider declined the request.',
                'provider_code' => $e->providerCode ?: null,
            ], 422);
        } catch (ProviderTransientException $e) {
            return response()->json(['message' => 'Provider temporarily unavailable.'], 503);
        }

        return response()->json($result, 200);
    }
}
