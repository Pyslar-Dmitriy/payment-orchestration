<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Enqueues a signed merchant callback notification for async delivery.
 * Implemented in TASK-063; enriched with merchant context in TASK-111.
 */
#[ActivityInterface(prefix: 'MerchantCallback.')]
interface MerchantCallbackActivity
{
    /**
     * @param  string  $resourceUuid  Payment UUID (or refund UUID for refund events).
     * @param  string  $merchantId  Merchant who owns the payment/refund.
     * @param  int  $amountValue  Amount in minor currency units.
     * @param  string  $amountCurrency  ISO-4217 three-letter currency code.
     * @param  string  $eventType  Fully-qualified event type (e.g. payment.captured).
     * @param  string  $correlationId  Correlation ID propagated from the workflow.
     * @param  string|null  $refundId  Refund UUID, present only for refund events.
     */
    #[ActivityMethod(name: 'triggerCallback')]
    public function triggerCallback(
        string $resourceUuid,
        string $merchantId,
        int $amountValue,
        string $amountCurrency,
        string $eventType,
        string $correlationId,
        ?string $refundId = null,
    ): void;
}
