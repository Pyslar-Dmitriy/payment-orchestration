<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Domain\DTO\ProviderCallResult;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Submits an authorize-and-capture request to the selected provider gateway.
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
}
