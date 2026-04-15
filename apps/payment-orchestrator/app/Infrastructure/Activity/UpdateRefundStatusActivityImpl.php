<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use App\Domain\Activity\UpdateRefundStatusActivity;
use App\Infrastructure\Http\PaymentDomainClient;
use Illuminate\Support\Facades\Log;

final class UpdateRefundStatusActivityImpl implements UpdateRefundStatusActivity
{
    public function __construct(private readonly PaymentDomainClient $client) {}

    public function markPendingProvider(string $refundUuid, string $correlationId): void
    {
        Log::info('Marking refund as pending_provider', [
            'refund_uuid' => $refundUuid,
            'correlation_id' => $correlationId,
        ]);

        $this->client->transitionRefundStatus($refundUuid, [
            'status' => 'pending_provider',
            'correlation_id' => $correlationId,
        ]);
    }

    public function markCompleted(string $refundUuid, string $correlationId): void
    {
        Log::info('Marking refund as succeeded', [
            'refund_uuid' => $refundUuid,
            'correlation_id' => $correlationId,
        ]);

        $this->client->transitionRefundStatus($refundUuid, [
            'status' => 'succeeded',
            'correlation_id' => $correlationId,
        ]);
    }

    public function markFailed(string $refundUuid, string $correlationId, ?string $reason = null): void
    {
        Log::info('Marking refund as failed', [
            'refund_uuid' => $refundUuid,
            'correlation_id' => $correlationId,
            'reason' => $reason,
        ]);

        $payload = [
            'status' => 'failed',
            'correlation_id' => $correlationId,
        ];

        if ($reason !== null) {
            $payload['failure_reason'] = $reason;
        }

        $this->client->transitionRefundStatus($refundUuid, $payload);
    }

    public function markRequiresReconciliation(
        string $refundUuid,
        string $correlationId,
        string $failedStep,
    ): void {
        Log::warning('Marking refund as requires_reconciliation', [
            'refund_uuid' => $refundUuid,
            'correlation_id' => $correlationId,
            'failed_step' => $failedStep,
        ]);

        $this->client->transitionRefundStatus($refundUuid, [
            'status' => 'requires_reconciliation',
            'correlation_id' => $correlationId,
            'failed_step' => $failedStep,
        ]);
    }
}
