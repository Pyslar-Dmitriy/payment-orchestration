<?php

declare(strict_types=1);

namespace App\Domain\Provider\DTO;

/**
 * Input to the provider routing algorithm.
 *
 * @param  string[]  $excludedProviderKeys  Provider keys to skip (used on fallback after hard failure).
 */
final readonly class RoutingRequest
{
    public function __construct(
        public string $currency,
        public string $country,
        public ?string $merchantType,
        public array $excludedProviderKeys = [],
    ) {}
}
