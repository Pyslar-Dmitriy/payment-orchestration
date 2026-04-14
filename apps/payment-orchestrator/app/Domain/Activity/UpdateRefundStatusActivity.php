<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Calls the payment-domain service to transition refund status.
 * Implemented in TASK-063.
 */
#[ActivityInterface(prefix: 'UpdateRefundStatus.')]
interface UpdateRefundStatusActivity
{
    #[ActivityMethod(name: 'markPendingProvider')]
    public function markPendingProvider(string $refundUuid, string $correlationId): void;

    #[ActivityMethod(name: 'markCompleted')]
    public function markCompleted(string $refundUuid, string $correlationId): void;

    #[ActivityMethod(name: 'markFailed')]
    public function markFailed(string $refundUuid, string $correlationId, ?string $reason = null): void;

    /**
     * Transitions the refund to requires_reconciliation (ADR-010 Class B compensation).
     * Used only when the ledger step fails permanently after the provider has already processed the refund.
     */
    #[ActivityMethod(name: 'markRequiresReconciliation')]
    public function markRequiresReconciliation(
        string $refundUuid,
        string $correlationId,
        string $failedStep,
    ): void;
}