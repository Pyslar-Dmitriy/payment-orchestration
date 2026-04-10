<?php

namespace App\Application\Refund\DTO;

readonly class InitiateRefundCommand
{
    public function __construct(
        public string $paymentId,
        public string $merchantId,
        public int $amount,
        public string $currency,
        public string $correlationId,
    ) {}
}
