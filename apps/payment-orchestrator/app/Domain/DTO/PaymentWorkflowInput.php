<?php

declare(strict_types=1);

namespace App\Domain\DTO;

final class PaymentWorkflowInput
{
    public function __construct(
        public readonly string $paymentUuid,
        public readonly string $merchantId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $country,
        public readonly string $correlationId,
    ) {}
}
