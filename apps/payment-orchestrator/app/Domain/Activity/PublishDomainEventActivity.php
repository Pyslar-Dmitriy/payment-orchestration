<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Publishes domain events to the Kafka event bus.
 * Implemented in TASK-063.
 */
#[ActivityInterface(prefix: 'PublishDomainEvent.')]
interface PublishDomainEventActivity
{
    #[ActivityMethod(name: 'publishPaymentCaptured')]
    public function publishPaymentCaptured(string $paymentUuid, string $correlationId): void;

    #[ActivityMethod(name: 'publishPaymentFailed')]
    public function publishPaymentFailed(string $paymentUuid, string $correlationId): void;

    /**
     * Publishes PaymentRequiresReconciliation — the ADR-010 Class B/C operator alert event.
     *
     * @param  string  $failedStep  The activity that failed (e.g. 'ledger_post').
     * @param  string  $lastKnownProviderStatus  Provider-confirmed status at the point of failure.
     * @param  string  $failureReason  Temporal activity failure message for diagnostics.
     */
    #[ActivityMethod(name: 'publishPaymentRequiresReconciliation')]
    public function publishPaymentRequiresReconciliation(
        string $paymentUuid,
        string $correlationId,
        string $failedStep,
        string $lastKnownProviderStatus,
        string $failureReason,
    ): void;
}
