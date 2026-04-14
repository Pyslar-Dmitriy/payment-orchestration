<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Domain\DTO\ProviderCallResult;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Submits payment and refund requests to the selected provider gateway.
 * Implemented in TASK-063.
 */
#[ActivityInterface(prefix: 'ProviderCall.')]
interface ProviderCallActivity
{
    /**
     * Sends the authorize+capture request to the provider.
     * Returns isAsync=true when the provider processes asynchronously (result arrives via webhook).
     * Returns isAsync=false with the final providerStatus when the result is synchronous.
     *
     * @throws \RuntimeException on non-retryable (hard) provider failures.
     */
    #[ActivityMethod(name: 'authorizeAndCapture')]
    public function authorizeAndCapture(
        string $paymentUuid,
        string $providerKey,
        string $correlationId,
    ): ProviderCallResult;

    /**
     * Sends the refund request to the provider.
     * Returns isAsync=true when the provider processes asynchronously (result arrives via webhook).
     * Returns isAsync=false with the final providerStatus when the result is synchronous.
     *
     * @throws \RuntimeException on non-retryable (hard) provider failures.
     */
    #[ActivityMethod(name: 'refund')]
    public function refund(
        string $refundUuid,
        string $paymentUuid,
        string $providerKey,
        string $correlationId,
    ): ProviderCallResult;
}
