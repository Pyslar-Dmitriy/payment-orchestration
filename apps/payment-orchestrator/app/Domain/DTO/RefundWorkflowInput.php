<?php

declare(strict_types=1);

namespace App\Domain\DTO;

final class RefundWorkflowInput
{
    public function __construct(
        public readonly string $refundUuid,
        public readonly string $paymentUuid,
        public readonly string $merchantId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $providerKey,
        public readonly string $correlationId,
    ) {}
}
