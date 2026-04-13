<?php

declare(strict_types=1);

namespace App\Domain\DTO;

final class ProviderStatusResult
{
    public function __construct(
        public readonly string $providerStatus,
        /** True when the provider confirms funds were captured. */
        public readonly bool $isCaptured,
        /** True when the provider confirms authorization succeeded but capture has not yet occurred. */
        public readonly bool $isAuthorized,
        /** True when the provider confirms the payment failed (no funds moved). */
        public readonly bool $isFailed,
    ) {}
}
