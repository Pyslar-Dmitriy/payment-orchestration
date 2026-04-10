<?php

namespace App\Application\Payment\DTO;

readonly class UpdatePaymentStatusCommand
{
    public function __construct(
        public string $paymentId,
        public string $merchantId,
        public string $correlationId,
        public ?string $reason = null,
        public ?string $failureCode = null,
        public ?string $failureReason = null,
    ) {}
}
