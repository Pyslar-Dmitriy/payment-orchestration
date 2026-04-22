<?php

declare(strict_types=1);

namespace App\Application\DispatchCallback;

final class DispatchCallbackCommand
{
    public function __construct(
        public readonly string $paymentId,
        public readonly string $merchantId,
        public readonly string $eventType,
        public readonly int $amountValue,
        public readonly string $amountCurrency,
        public readonly string $status,
        public readonly string $occurredAt,
        public readonly string $correlationId,
        public readonly ?string $refundId = null,
        public readonly ?string $idempotencyKey = null,
    ) {}
}
