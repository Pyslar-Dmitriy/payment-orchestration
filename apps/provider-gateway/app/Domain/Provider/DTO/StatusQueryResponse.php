<?php

declare(strict_types=1);

namespace App\Domain\Provider\DTO;

final class StatusQueryResponse
{
    public function __construct(
        public readonly string $providerStatus,
        public readonly bool $isCaptured,
        public readonly bool $isAuthorized,
        public readonly bool $isFailed,
    ) {}
}
