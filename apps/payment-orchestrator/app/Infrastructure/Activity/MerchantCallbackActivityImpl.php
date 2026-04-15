<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use App\Domain\Activity\MerchantCallbackActivity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Enqueues a signed merchant callback notification for async delivery via the
 * merchant-callback-delivery service. Implemented in EPIC-11.
 */
final class MerchantCallbackActivityImpl implements MerchantCallbackActivity
{
    private string $baseUrl;

    private int $connectTimeout;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.callback_delivery.base_url'), '/');
        $this->connectTimeout = (int) config('services.callback_delivery.connect_timeout', 2);
        $this->timeout = (int) config('services.callback_delivery.timeout', 10);
    }

    public function triggerCallback(string $paymentUuid, string $status, string $correlationId): void
    {
        Log::info('Triggering merchant callback', [
            'payment_uuid' => $paymentUuid,
            'status' => $status,
            'correlation_id' => $correlationId,
        ]);

        $client = Http::connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->withHeaders(['Accept' => 'application/json']);

        try {
            /** @var Response $response */
            $response = $client->post("{$this->baseUrl}/api/v1/callbacks/dispatch", [
                'payment_uuid' => $paymentUuid,
                'status' => $status,
                'correlation_id' => $correlationId,
            ]);
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
}
