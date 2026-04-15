<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use App\Domain\Activity\ProviderStatusQueryActivity;
use App\Domain\DTO\ProviderStatusResult;
use App\Domain\DTO\RefundStatusResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Queries the provider-gateway for the current status of a payment or refund.
 * Used during the webhook timeout recovery path. Provider gateway is implemented in TASK-071.
 */
final class ProviderStatusQueryActivityImpl implements ProviderStatusQueryActivity
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

    public function queryStatus(
        string $paymentUuid,
        string $providerKey,
        string $correlationId,
    ): ProviderStatusResult {
        Log::info('Querying provider for payment status', [
            'payment_uuid' => $paymentUuid,
            'provider_key' => $providerKey,
            'correlation_id' => $correlationId,
        ]);

        $response = $this->send(
            fn ($client) => $client->get("{$this->baseUrl}/api/v1/provider/payments/{$paymentUuid}/status", [
                'provider_key' => $providerKey,
                'correlation_id' => $correlationId,
            ]),
        );

        $data = $response->json();

        return new ProviderStatusResult(
            providerStatus: $data['provider_status'],
            isCaptured: (bool) $data['is_captured'],
            isAuthorized: (bool) $data['is_authorized'],
            isFailed: (bool) $data['is_failed'],
        );
    }

    public function queryRefundStatus(
        string $refundUuid,
        string $providerKey,
        string $correlationId,
    ): RefundStatusResult {
        Log::info('Querying provider for refund status', [
            'refund_uuid' => $refundUuid,
            'provider_key' => $providerKey,
            'correlation_id' => $correlationId,
        ]);

        $response = $this->send(
            fn ($client) => $client->get("{$this->baseUrl}/api/v1/provider/refunds/{$refundUuid}/status", [
                'provider_key' => $providerKey,
                'correlation_id' => $correlationId,
            ]),
        );

        $data = $response->json();

        return new RefundStatusResult(
            providerStatus: $data['provider_status'],
            isRefunded: (bool) $data['is_refunded'],
            isFailed: (bool) $data['is_failed'],
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
