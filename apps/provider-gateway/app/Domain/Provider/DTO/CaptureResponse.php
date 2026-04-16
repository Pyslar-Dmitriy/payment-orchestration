<?php

declare(strict_types=1);

namespace App\Domain\Provider\DTO;

final class CaptureResponse
{
    /**
     * @param  string  $providerReference  PSP-assigned transaction identifier.
     * @param  string  $providerStatus  Normalized internal status (captured, failed).
     * @param  bool  $isAsync  True when the final result will arrive via webhook.
     */
    public function __construct(
        public readonly string $providerReference,
        public readonly string $providerStatus,
        public readonly bool $isAsync,
    ) {}
}
