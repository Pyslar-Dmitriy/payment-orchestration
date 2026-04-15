<?php

namespace App\Application\Refund\DTO;

final class InternalUpdateRefundStatusCommand
{
    public function __construct(
        public readonly string $refundId,
        public readonly string $correlationId,
        public readonly ?string $failedStep = null,
        public readonly ?string $failureReason = null,
    ) {}
}
