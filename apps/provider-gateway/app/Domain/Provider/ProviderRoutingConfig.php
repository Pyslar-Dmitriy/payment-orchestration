<?php

declare(strict_types=1);

namespace App\Domain\Provider;

/**
 * Immutable value object representing one provider's routing eligibility rules.
 *
 * Instances are built from configuration (+ runtime cache overrides) and passed
 * to ProviderRouter for candidate selection. No adapter references here — this
 * is pure routing metadata.
 */
final readonly class ProviderRoutingConfig
{
    /**
     * @param  string[]  $currencies  ISO 4217 codes this provider accepts.
     * @param  string[]  $countries  ISO 3166-1 alpha-2 merchant country codes.
     * @param  string[]  $merchantTypes  Whitelist of merchant categories; empty = all accepted.
     */
    public function __construct(
        public string $providerKey,
        public array $currencies,
        public array $countries,
        public array $merchantTypes,
        public int $priority,
        public bool $available,
    ) {}
}
