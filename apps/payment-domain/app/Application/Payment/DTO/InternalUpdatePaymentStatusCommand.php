<?php

namespace App\Application\Payment\DTO;

final class InternalUpdatePaymentStatusCommand
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $correlationId,
        public readonly ?string $failedStep = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureReason = null,
    ) {}
}
