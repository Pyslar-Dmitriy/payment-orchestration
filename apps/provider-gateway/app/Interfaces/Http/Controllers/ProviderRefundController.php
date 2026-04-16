<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Application\Provider\RefundHandler;
use App\Domain\Provider\Exception\ProviderHardFailureException;
use App\Domain\Provider\Exception\ProviderNotFoundException;
use App\Domain\Provider\Exception\ProviderTransientException;
use App\Interfaces\Http\Requests\ProviderRefundRequest;
use Illuminate\Http\JsonResponse;

final class ProviderRefundController
{
    public function __construct(private readonly RefundHandler $handler) {}

    public function __invoke(ProviderRefundRequest $request): JsonResponse
    {
        try {
            $result = $this->handler->handle(
                refundUuid: $request->input('refund_uuid'),
                paymentUuid: $request->input('payment_uuid'),
                providerKey: $request->input('provider_key'),
                correlationId: $request->input('correlation_id'),
                providerReference: (string) $request->input('provider_reference', ''),
                amount: (int) $request->input('amount', 0),
                currency: (string) $request->input('currency', ''),
            );
        } catch (ProviderNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ProviderHardFailureException $e) {
            return response()->json([
                'message' => 'Provider declined the refund.',
                'provider_code' => $e->providerCode ?: null,
            ], 422);
        } catch (ProviderTransientException $e) {
            return response()->json(['message' => 'Provider temporarily unavailable.'], 503);
        }

        return response()->json($result, 200);
    }
}
