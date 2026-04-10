<?php

namespace App\Application\Payment\DTO;

use JsonSerializable;

readonly class InitiatePaymentResult implements JsonSerializable
{
    public function __construct(
        public string $paymentId,
        public ?string $attemptId,
        public string $status,
        public bool $created,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'attempt_id' => $this->attemptId,
            'status' => $this->status,
        ];
    }

    public function isCreated(): bool
    {
        return $this->created;
    }
}
