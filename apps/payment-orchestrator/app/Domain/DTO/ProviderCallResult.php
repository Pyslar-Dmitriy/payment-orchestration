<?php

declare(strict_types=1);

namespace App\Domain\DTO;

final class ProviderCallResult
{
    public function __construct(
        public readonly string $providerReference,
        public readonly string $providerStatus,
        /** Whether the provider processes the request asynchronously (i.e. result arrives via webhook). */
        public readonly bool $isAsync,
    ) {}
}
