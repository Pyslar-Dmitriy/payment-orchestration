<?php

declare(strict_types=1);

namespace App\Domain\Provider\DTO;

final class RefundStatusQueryRequest
{
    public function __construct(
        public readonly string $refundUuid,
        public readonly string $correlationId,
        public readonly ?string $providerReference = null,
    ) {}
}
