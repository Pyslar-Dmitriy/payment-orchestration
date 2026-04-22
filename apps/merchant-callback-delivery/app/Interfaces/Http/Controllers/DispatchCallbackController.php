<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Application\DispatchCallback\DispatchCallbackCommand;
use App\Application\DispatchCallback\DispatchCallbackHandler;
use App\Interfaces\Http\Requests\DispatchCallbackRequest;
use Illuminate\Http\JsonResponse;

final class DispatchCallbackController
{
    public function __construct(private readonly DispatchCallbackHandler $handler) {}

    public function __invoke(DispatchCallbackRequest $request): JsonResponse
    {
        $command = new DispatchCallbackCommand(
            paymentId: $request->string('payment_id')->toString(),
            merchantId: $request->string('merchant_id')->toString(),
            eventType: $request->string('event_type')->toString(),
            amountValue: (int) $request->integer('amount_value'),
            amountCurrency: $request->string('amount_currency')->toString(),
            status: $request->string('status')->toString(),
            occurredAt: $request->string('occurred_at')->toString(),
            correlationId: $request->string('correlation_id')->toString(),
            refundId: $request->filled('refund_id') ? $request->string('refund_id')->toString() : null,
            idempotencyKey: $request->filled('idempotency_key') ? $request->string('idempotency_key')->toString() : null,
        );

        $dispatched = $this->handler->handle($command);

        return response()->json([
            'dispatched_count' => count($dispatched),
            'dispatched' => array_map(
                fn ($d) => ['delivery_id' => $d->deliveryId, 'endpoint_url' => $d->endpointUrl],
                $dispatched,
            ),
        ], 202);
    }
}
