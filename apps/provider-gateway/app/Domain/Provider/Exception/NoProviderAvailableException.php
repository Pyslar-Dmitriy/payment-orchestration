<?php

declare(strict_types=1);

namespace App\Domain\Provider\Exception;

use RuntimeException;

/**
 * Thrown when no configured provider satisfies the routing request.
 *
 * This is a domain-level rejection, not a runtime error — the payment should be
 * declined before the workflow starts. Callers must not retry without changing
 * the routing parameters.
 */
final class NoProviderAvailableException extends RuntimeException
{
    public function __construct(
        public readonly string $currency,
        public readonly string $country,
        public readonly ?string $merchantType,
    ) {
        $merchantTypeLabel = $merchantType !== null ? ", merchant_type={$merchantType}" : '';
        parent::__construct(
            "No provider available for currency={$currency}, country={$country}{$merchantTypeLabel}."
        );
    }
}
