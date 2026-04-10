<?php

namespace App\Application\Refund\DTO;

use JsonSerializable;

readonly class InitiateRefundResult implements JsonSerializable
{
    public function __construct(
        public string $refundId,
        public string $paymentId,
        public string $status,
        public int $amount,
        public string $currency,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'refund_id' => $this->refundId,
            'payment_id' => $this->paymentId,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
