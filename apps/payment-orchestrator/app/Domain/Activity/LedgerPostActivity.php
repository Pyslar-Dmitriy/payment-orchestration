<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Creates append-only double-entry ledger records in the ledger-service.
 * Implemented in TASK-063. Uses an idempotency key so safe to retry.
 */
#[ActivityInterface(prefix: 'LedgerPost.')]
interface LedgerPostActivity
{
    #[ActivityMethod(name: 'postCapture')]
    public function postCapture(string $paymentUuid, string $correlationId): void;

    #[ActivityMethod(name: 'postRefund')]
    public function postRefund(string $refundUuid, string $correlationId): void;
}
