<?php

namespace App\Application\Refund\DTO;

use JsonSerializable;

readonly class UpdateRefundStatusResult implements JsonSerializable
{
    public function __construct(
        public string $refundId,
        public string $status,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'refund_id' => $this->refundId,
            'status' => $this->status,
        ];
    }
}
