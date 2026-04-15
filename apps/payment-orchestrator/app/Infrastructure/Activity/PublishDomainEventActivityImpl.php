<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use App\Domain\Activity\PublishDomainEventActivity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publishes orchestration-level domain events to the Kafka event bus via an
 * HTTP endpoint on the payment-domain outbox bridge. Full Kafka integration
 * is wired in EPIC-12.
 */
final class PublishDomainEventActivityImpl implements PublishDomainEventActivity
{
    private string $baseUrl;

    private int $connectTimeout;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.payment_domain.base_url'), '/');
        $this->connectTimeout = (int) config('services.payment_domain.connect_timeout', 2);
        $this->timeout = (int) config('services.payment_domain.timeout', 5);
    }

    public function publishPaymentCaptured(string $paymentUuid, string $correlationId): void
    {
        Log::info('Publishing payment.captured orchestration event', [
            'payment_uuid' => $paymentUuid,
            'correlation_id' => $correlationId,
        ]);

        $this->publish('payment.orchestration.captured.v1', [
            'payment_uuid' => $paymentUuid,
            'correlation_id' => $correlationId,
        ]);
    }

    public function publishPaymentFailed(string $paymentUuid, string $correlationId): void
    {
        Log::info('Publishing payment.failed orchestration event', [
            'payment_uuid' => $paymentUuid,
            'correlation_id' => $correlationId,
        ]);

        $this->publish('payment.orchestration.failed.v1', [
            'payment_uuid' => $paymentUuid,
            'correlation_id' => $correlationId,
        ]);
    }

    public function publishPaymentRequiresReconciliation(
        string $paymentUuid,
        string $correlationId,
        string $failedStep,
        string $lastKnownProviderStatus,
        string $failureReason,
    ): void {
        Log::warning('Publishing payment.requires_reconciliation orchestration event', [
            'payment_uuid' => $paymentUuid,
            'correlation_id' => $correlationId,
            'failed_step' => $failedStep,
            'last_known_provider_status' => $lastKnownProviderStatus,
        ]);

        $this->publish('payment.orchestration.requires_reconciliation.v1', [
            'payment_uuid' => $paymentUuid,
            'correlation_id' => $correlationId,
            'failed_step' => $failedStep,
            'last_known_provider_status' => $lastKnownProviderStatus,
            'failure_reason' => $failureReason,
        ]);
    }

    public function publishRefundCompleted(string $refundUuid, string $correlationId): void
    {
        Log::info('Publishing refund.completed orchestration event', [
            'refund_uuid' => $refundUuid,
            'correlation_id' => $correlationId,
        ]);

        $this->publish('refund.orchestration.completed.v1', [
            'refund_uuid' => $refundUuid,
            'correlation_id' => $correlationId,
        ]);
    }

    public function publishRefundFailed(string $refundUuid, string $correlationId): void
    {
        Log::info('Publishing refund.failed orchestration event', [
            'refund_uuid' => $refundUuid,
            'correlation_id' => $correlationId,
        ]);

        $this->publish('refund.orchestration.failed.v1', [
            'refund_uuid' => $refundUuid,
            'correlation_id' => $correlationId,
        ]);
    }

    public function publishRefundRequiresReconciliation(
        string $refundUuid,
        string $correlationId,
        string $failedStep,
        string $lastKnownProviderStatus,
        string $failureReason,
    ): void {
        Log::warning('Publishing refund.requires_reconciliation orchestration event', [
            'refund_uuid' => $refundUuid,
            'correlation_id' => $correlationId,
            'failed_step' => $failedStep,
            'last_known_provider_status' => $lastKnownProviderStatus,
        ]);

        $this->publish('refund.orchestration.requires_reconciliation.v1', [
            'refund_uuid' => $refundUuid,
            'correlation_id' => $correlationId,
            'failed_step' => $failedStep,
            'last_known_provider_status' => $lastKnownProviderStatus,
            'failure_reason' => $failureReason,
        ]);
    }

    private function publish(string $eventType, array $payload): void
    {
        $client = Http::connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->withHeaders([
                'X-Internal-Secret' => (string) config('services.payment_domain.internal_secret'),
                'Accept' => 'application/json',
            ]);

        try {
            /** @var Response $response */
            $response = $client->post("{$this->baseUrl}/api/internal/v1/events", [
                'event_type' => $eventType,
                'payload' => $payload,
            ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException(
                "event bus unreachable: {$e->getMessage()}",
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                "event bus returned {$response->status()}: {$response->body()}",
            );
        }
    }
}
