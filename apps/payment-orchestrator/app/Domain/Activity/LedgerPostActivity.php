<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Creates an append-only double-entry ledger record in the ledger-service.
 * Implemented in TASK-063. Uses an idempotency key so safe to retry.
 */
#[ActivityInterface(prefix: 'LedgerPost.')]
interface LedgerPostActivity
{
    #[ActivityMethod(name: 'postCapture')]
    public function postCapture(string $paymentUuid, string $correlationId): void;
}
