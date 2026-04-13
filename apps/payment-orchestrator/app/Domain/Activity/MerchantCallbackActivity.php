<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Enqueues a signed merchant callback notification for async delivery.
 * Implemented in TASK-063.
 */
#[ActivityInterface(prefix: 'MerchantCallback.')]
interface MerchantCallbackActivity
{
    /**
     * @param  string  $status  Terminal payment status to include in the callback payload.
     */
    #[ActivityMethod(name: 'triggerCallback')]
    public function triggerCallback(string $paymentUuid, string $status, string $correlationId): void;
}
