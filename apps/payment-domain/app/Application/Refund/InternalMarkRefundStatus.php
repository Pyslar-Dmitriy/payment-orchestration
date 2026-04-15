<?php

namespace App\Application\Refund;

use App\Application\Refund\DTO\InternalUpdateRefundStatusCommand;
use App\Domain\Refund\Exceptions\InvalidRefundTransitionException;
use App\Domain\Refund\Exceptions\RefundNotFoundException;
use App\Domain\Refund\Refund;
use App\Domain\Refund\RefundStatus;
use App\Infrastructure\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;

/**
 * Internal-only use case for refund status transitions triggered by the orchestrator.
 * Looks up the refund by UUID without merchant scoping — callers must be trusted
 * (enforced at the route level via InternalServiceMiddleware).
 */
final class InternalMarkRefundStatus
{
    /**
     * @throws RefundNotFoundException
     * @throws InvalidRefundTransitionException
     */
    public function execute(
        InternalUpdateRefundStatusCommand $command,
        RefundStatus $status,
    ): array {
        $refund = Refund::where('id', $command->refundId)->first();

        if ($refund === null) {
            throw new RefundNotFoundException($command->refundId);
        }

        return DB::transaction(function () use ($refund, $command, $status): array {
            $refund->transition(
                $status,
                $command->correlationId,
                failureReason: $command->failureReason,
            );

            $eventType = $this->resolveEventType($status);
            $payload = $this->buildPayload($refund, $command, $status);

            OutboxEvent::create([
                'aggregate_type' => 'Refund',
                'aggregate_id' => $refund->id,
                'event_type' => $eventType,
                'payload' => $payload,
            ]);

            return [
                'refund_id' => $refund->id,
                'status' => $refund->status->value,
            ];
        });
    }

    private function resolveEventType(RefundStatus $status): string
    {
        return match ($status) {
            RefundStatus::PENDING_PROVIDER => 'refund.pending_provider.v1',
            RefundStatus::SUCCEEDED => 'refund.succeeded.v1',
            RefundStatus::FAILED => 'refund.failed.v1',
            RefundStatus::REQUIRES_RECONCILIATION => 'refund.requires_reconciliation.v1',
            default => throw new \LogicException("Unexpected internal refund status: {$status->value}"),
        };
    }

    private function buildPayload(Refund $refund, InternalUpdateRefundStatusCommand $command, RefundStatus $status): array
    {
        $base = [
            'refund_id' => $refund->id,
            'payment_id' => $refund->payment_id,
            'merchant_id' => $refund->merchant_id,
            'status' => $refund->status->value,
            'correlation_id' => $command->correlationId,
            'occurred_at' => now()->toIso8601String(),
        ];

        if ($status === RefundStatus::FAILED) {
            $base['failure_reason'] = $refund->failure_reason;
        }

        if ($status === RefundStatus::REQUIRES_RECONCILIATION) {
            $base['failed_step'] = $command->failedStep;
        }

        return $base;
    }
}
