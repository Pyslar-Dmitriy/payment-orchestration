<?php

namespace App\Application\Payment\DTO;

use JsonSerializable;

readonly class UpdatePaymentStatusResult implements JsonSerializable
{
    public function __construct(
        public string $paymentId,
        public string $status,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'status' => $this->status,
        ];
    }
}
