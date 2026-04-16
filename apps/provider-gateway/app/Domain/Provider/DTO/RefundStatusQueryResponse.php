<?php

declare(strict_types=1);

namespace App\Domain\Provider\DTO;

final class RefundStatusQueryResponse
{
    public function __construct(
        public readonly string $providerStatus,
        public readonly bool $isRefunded,
        public readonly bool $isFailed,
    ) {}
}
