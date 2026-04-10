<?php

namespace App\Application\Refund\DTO;

use JsonSerializable;

readonly class GetRefundResult implements JsonSerializable
{
    public function __construct(
        public string $refundId,
        public string $paymentId,
        public string $status,
        public int $amount,
        public string $currency,
        public string $correlationId,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'refund_id' => $this->refundId,
            'payment_id' => $this->paymentId,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'correlation_id' => $this->correlationId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
