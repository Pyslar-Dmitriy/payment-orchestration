<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Selects which provider-gateway route to use for a given payment.
 * Implemented in TASK-073; the workflow depends on this interface.
 */
#[ActivityInterface(prefix: 'ProviderRouting.')]
interface ProviderRoutingActivity
{
    /**
     * Returns the provider key to use for the payment.
     * Pass previously failed provider keys in $excludedProviders to select a fallback.
     *
     * @param  list<string>  $excludedProviders
     *
     * @throws \RuntimeException when no eligible provider is available after exclusions.
     */
    #[ActivityMethod(name: 'selectProvider')]
    public function selectProvider(
        string $paymentUuid,
        string $currency,
        string $country,
        array $excludedProviders = [],
    ): string;
}
