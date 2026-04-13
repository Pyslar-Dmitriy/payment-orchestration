<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Calls the payment-domain service to transition payment status.
 * Implemented in TASK-063.
 */
#[ActivityInterface(prefix: 'UpdatePaymentStatus.')]
interface UpdatePaymentStatusActivity
{
    #[ActivityMethod(name: 'markPendingProvider')]
    public function markPendingProvider(string $paymentUuid, string $correlationId): void;

    #[ActivityMethod(name: 'markAuthorized')]
    public function markAuthorized(string $paymentUuid, string $correlationId): void;

    #[ActivityMethod(name: 'markCaptured')]
    public function markCaptured(string $paymentUuid, string $correlationId): void;

    #[ActivityMethod(name: 'markFailed')]
    public function markFailed(string $paymentUuid, string $correlationId, ?string $reason = null): void;

    /**
     * Transitions the payment to requires_reconciliation (ADR-010 Class B/C compensation).
     * Used only when a post-side-effect step fails permanently.
     */
    #[ActivityMethod(name: 'markRequiresReconciliation')]
    public function markRequiresReconciliation(
        string $paymentUuid,
        string $correlationId,
        string $failedStep,
    ): void;
}
