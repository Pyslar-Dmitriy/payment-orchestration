<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use App\Domain\Activity\LedgerPostActivity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Creates append-only double-entry ledger records in the ledger-service.
 * Uses the correlation_id as idempotency key — safe to retry.
 * Ledger service is implemented in EPIC-10.
 */
final class LedgerPostActivityImpl implements LedgerPostActivity
{
    private string $baseUrl;

    private int $connectTimeout;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.ledger_service.base_url'), '/');
        $this->connectTimeout = (int) config('services.ledger_service.connect_timeout', 2);
        $this->timeout = (int) config('services.ledger_service.timeout', 10);
    }

    public function postCapture(string $paymentUuid, string $correlationId): void
    {
        Log::info('Posting capture ledger entry', [
            'payment_uuid' => $paymentUuid,
            'correlation_id' => $correlationId,
        ]);

        $this->send(
            fn ($client) => $client->post("{$this->baseUrl}/api/v1/ledger/capture", [
                'payment_uuid' => $paymentUuid,
                'correlation_id' => $correlationId,
                'idempotency_key' => "capture:{$paymentUuid}:{$correlationId}",
            ]),
        );
    }

    public function postRefund(string $refundUuid, string $correlationId): void
    {
        Log::info('Posting refund ledger entry', [
            'refund_uuid' => $refundUuid,
            'correlation_id' => $correlationId,
        ]);

        $this->send(
            fn ($client) => $client->post("{$this->baseUrl}/api/v1/ledger/refund", [
                'refund_uuid' => $refundUuid,
                'correlation_id' => $correlationId,
                'idempotency_key' => "refund:{$refundUuid}:{$correlationId}",
            ]),
        );
    }

    private function send(callable $request): void
    {
        $client = Http::connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->withHeaders(['Accept' => 'application/json']);

        try {
            /** @var Response $response */
            $response = $request($client);
        } catch (ConnectionException $e) {
            throw new \RuntimeException(
                "ledger-service unreachable: {$e->getMessage()}",
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                "ledger-service returned {$response->status()}: {$response->body()}",
            );
        }
    }
}
