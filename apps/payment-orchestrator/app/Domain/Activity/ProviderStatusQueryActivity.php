<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Domain\DTO\ProviderStatusResult;
use App\Domain\DTO\RefundStatusResult;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Queries the provider for the current payment or refund status.
 * Used only during the webhook timeout recovery path (30-minute timeout).
 * Implemented in TASK-063.
 */
#[ActivityInterface(prefix: 'ProviderStatusQuery.')]
interface ProviderStatusQueryActivity
{
    /**
     * Polls the provider gateway for the current status of the given payment.
     * isCaptured and isFailed are mutually exclusive; both false means status is unknown/pending.
     */
    #[ActivityMethod(name: 'queryStatus')]
    public function queryStatus(
        string $paymentUuid,
        string $providerKey,
        string $correlationId,
    ): ProviderStatusResult;

    /**
     * Polls the provider gateway for the current status of the given refund.
     * isRefunded and isFailed are mutually exclusive; both false means status is unknown/pending.
     */
    #[ActivityMethod(name: 'queryRefundStatus')]
    public function queryRefundStatus(
        string $refundUuid,
        string $providerKey,
        string $correlationId,
    ): RefundStatusResult;
}
