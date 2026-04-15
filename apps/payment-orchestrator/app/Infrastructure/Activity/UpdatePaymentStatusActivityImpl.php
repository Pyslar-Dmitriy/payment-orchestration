<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use App\Domain\Activity\UpdatePaymentStatusActivity;
use App\Infrastructure\Http\PaymentDomainClient;
use Illuminate\Support\Facades\Log;

final class UpdatePaymentStatusActivityImpl implements UpdatePaymentStatusActivity
{
    public function __construct(private readonly PaymentDomainClient $client) {}

    public function markPendingProvider(string $paymentUuid, string $correlationId): void
    {
        Log::info('Marking payment as pending_provider', [
            'payment_uuid' => $paymentUuid,
            'correlation_id' => $correlationId,
        ]);

        $this->client->transitionPaymentStatus($paymentUuid, [
            'status' => 'pending_provider',
            'correlation_id' => $correlationId,
        ]);
    }

    public function markAuthorized(string $paymentUuid, string $correlationId): void
    {
        Log::info('Marking payment as authorized', [
            'payment_uuid' => $paymentUuid,
            'correlation_id' => $correlationId,
        ]);

        $this->client->transitionPaymentStatus($paymentUuid, [
            'status' => 'authorized',
            'correlation_id' => $correlationId,
        ]);
    }

    public function markCaptured(string $paymentUuid, string $correlationId): void
    {
        Log::info('Marking payment as captured', [
            'payment_uuid' => $paymentUuid,
            'correlation_id' => $correlationId,
        ]);

        $this->client->transitionPaymentStatus($paymentUuid, [
            'status' => 'captured',
            'correlation_id' => $correlationId,
        ]);
    }

    public function markFailed(string $paymentUuid, string $correlationId, ?string $reason = null): void
    {
        Log::info('Marking payment as failed', [
            'payment_uuid' => $paymentUuid,
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

        $this->client->transitionPaymentStatus($paymentUuid, $payload);
    }

    public function markRequiresReconciliation(
        string $paymentUuid,
        string $correlationId,
        string $failedStep,
    ): void {
        Log::warning('Marking payment as requires_reconciliation', [
            'payment_uuid' => $paymentUuid,
            'correlation_id' => $correlationId,
            'failed_step' => $failedStep,
        ]);

        $this->client->transitionPaymentStatus($paymentUuid, [
            'status' => 'requires_reconciliation',
            'correlation_id' => $correlationId,
            'failed_step' => $failedStep,
        ]);
    }
}
