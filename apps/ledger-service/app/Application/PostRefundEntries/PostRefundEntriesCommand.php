<?php

declare(strict_types=1);

namespace App\Application\PostRefundEntries;

final readonly class PostRefundEntriesCommand
{
    public function __construct(
        public string $refundId,
        public string $paymentId,
        public string $merchantId,
        public int $amount,
        public string $currency,
        public string $correlationId,
        public ?string $causationId = null,
        public int $feeRefundAmount = 0,
    ) {}
}
