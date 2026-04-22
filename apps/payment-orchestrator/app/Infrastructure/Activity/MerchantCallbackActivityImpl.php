<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use App\Domain\Activity\MerchantCallbackActivity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MerchantCallbackActivityImpl implements MerchantCallbackActivity
{
    private string $baseUrl;

    private string $internalSecret;

    private int $connectTimeout;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.callback_delivery.base_url'), '/');
        $this->internalSecret = (string) config('services.internal.secret');
        $this->connectTimeout = (int) config('services.callback_delivery.connect_timeout', 2);
        $this->timeout = (int) config('services.callback_delivery.timeout', 10);
    }

    public function triggerCallback(
        string $resourceUuid,
        string $merchantId,
        int $amountValue,
        string $amountCurrency,
        string $eventType,
        string $correlationId,
        ?string $refundId = null,
    ): void {
        Log::info('Triggering merchant callback', [
            'payment_id' => $resourceUuid,
            'event_type' => $eventType,
            'correlation_id' => $correlationId,
        ]);

        $payload = [
            'payment_id' => $resourceUuid,
            'merchant_id' => $merchantId,
            'event_type' => $eventType,
            'amount_value' => $amountValue,
            'amount_currency' => $amountCurrency,
            'status' => $this->statusFromEventType($eventType),
            'occurred_at' => now()->toIso8601ZuluString(),
            'correlation_id' => $correlationId,
        ];

        if ($refundId !== null) {
            $payload['refund_id'] = $refundId;
        }

        $client = Http::connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Internal-Secret' => $this->internalSecret,
            ]);

        try {
            /** @var Response $response */
            $response = $client->post("{$this->baseUrl}/api/v1/callbacks/dispatch", $payload);
        } catch (ConnectionException $e) {
            throw new \RuntimeException(
                "merchant-callback-delivery unreachable: {$e->getMessage()}",
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                "merchant-callback-delivery returned {$response->status()}: {$response->body()}",
            );
        }
    }

    private function statusFromEventType(string $eventType): string
    {
        return match ($eventType) {
            'payment.captured' => 'captured',
            'payment.failed' => 'failed',
            'refund.completed' => 'refunded',
            'refund.failed' => 'failed',
            default => $eventType,
        };
    }
}
