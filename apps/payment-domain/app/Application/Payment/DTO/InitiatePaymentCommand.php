<?php

namespace App\Application\Payment\DTO;

readonly class InitiatePaymentCommand
{
    public function __construct(
        public string $merchantId,
        public int $amount,
        public string $currency,
        public string $externalReference,
        public string $idempotencyKey,
        public string $providerId,
        public ?string $customerReference,
        public ?string $paymentMethodReference,
        public ?array $metadata,
        public string $correlationId,
    ) {}
}