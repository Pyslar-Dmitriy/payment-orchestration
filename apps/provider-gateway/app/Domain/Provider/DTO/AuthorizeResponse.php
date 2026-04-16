<?php

declare(strict_types=1);

namespace App\Domain\Provider\DTO;

final class AuthorizeResponse
{
    /**
     * @param  string  $providerReference  PSP-assigned transaction identifier.
     * @param  string  $providerStatus  Normalized internal status (authorized, captured, failed).
     * @param  bool  $isAsync  True when the final result will arrive via webhook.
     * @param  bool  $isCaptured  True when the PSP captured funds atomically in one call.
     */
    public function __construct(
        public readonly string $providerReference,
        public readonly string $providerStatus,
        public readonly bool $isAsync,
        public readonly bool $isCaptured = false,
    ) {}
}
