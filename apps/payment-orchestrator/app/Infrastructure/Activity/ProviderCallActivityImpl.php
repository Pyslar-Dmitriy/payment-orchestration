<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use App\Domain\Activity\ProviderCallActivity;
use App\Domain\DTO\ProviderCallResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls the provider-gateway service to authorize, capture, or refund a payment.
 * Provider gateway is implemented in TASK-071.
 */
final class ProviderCallActivityImpl implements ProviderCallActivity
{
    private string $baseUrl;

    private int $connectTimeout;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.provider_gateway.base_url'), '/');
        $this->connectTimeout = (int) config('services.provider_gateway.connect_timeout', 2);
        $this->timeout = (int) config('services.provider_gateway.timeout', 30);
    }

    public function authorizeAndCapture(
        string $paymentUuid,
        string $providerKey,
        string $correlationId,
    ): ProviderCallResult {
        Log::info('Sending authorize+capture to provider gateway', [
            'payment_uuid' => $paymentUuid,
            'provider_key' => $providerKey,
            'correlation_id' => $correlationId,
        ]);

        $response = $this->send(
            fn ($client) => $client->post("{$this->baseUrl}/api/v1/provider/authorize", [
                'payment_uuid' => $paymentUuid,
                'provider_key' => $providerKey,
                'correlation_id' => $correlationId,
            ]),
        );

        $data = $response->json();

        return new ProviderCallResult(
            providerReference: $data['provider_reference'],
            providerStatus: $data['provider_status'],
            isAsync: (bool) $data['is_async'],
        );
    }

    public function refund(
        string $refundUuid,
        string $paymentUuid,
        string $providerKey,
        string $correlationId,
    ): ProviderCallResult {
        Log::info('Sending refund request to provider gateway', [
            'refund_uuid' => $refundUuid,
            'payment_uuid' => $paymentUuid,
            'provider_key' => $providerKey,
            'correlation_id' => $correlationId,
        ]);

        $response = $this->send(
            fn ($client) => $client->post("{$this->baseUrl}/api/v1/provider/refund", [
                'refund_uuid' => $refundUuid,
                'payment_uuid' => $paymentUuid,
                'provider_key' => $providerKey,
                'correlation_id' => $correlationId,
            ]),
        );

        $data = $response->json();

        return new ProviderCallResult(
            providerReference: $data['provider_reference'],
            providerStatus: $data['provider_status'],
            isAsync: (bool) $data['is_async'],
        );
    }

    private function send(callable $request): Response
    {
        $client = Http::connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->withHeaders(['Accept' => 'application/json']);

        try {
            /** @var Response $response */
            $response = $request($client);
        } catch (ConnectionException $e) {
            throw new \RuntimeException(
                "provider-gateway unreachable: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->successful()) {
            return $response;
        }

        throw new \RuntimeException(
            "provider-gateway returned {$response->status()}: {$response->body()}",
        );
    }
}
