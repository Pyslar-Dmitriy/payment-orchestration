<?php

namespace App\Application\Payment\DTO;

use JsonSerializable;

readonly class GetPaymentResult implements JsonSerializable
{
    public function __construct(
        public string $paymentId,
        public string $status,
        public int $amount,
        public string $currency,
        public ?string $providerReference,
        public ?string $failureReason,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'provider_reference' => $this->providerReference,
            'failure_reason' => $this->failureReason,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
