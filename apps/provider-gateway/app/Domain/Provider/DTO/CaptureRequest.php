<?php

declare(strict_types=1);

namespace App\Domain\Provider\DTO;

final class CaptureRequest
{
    public function __construct(
        public readonly string $paymentUuid,
        public readonly string $providerReference,
        public readonly string $correlationId,
        public readonly int $amount = 0,
        public readonly string $currency = '',
    ) {}
}
