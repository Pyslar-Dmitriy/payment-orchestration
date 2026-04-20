<?php

declare(strict_types=1);

namespace App\Application\PostCaptureEntries;

final readonly class PostCaptureEntriesCommand
{
    public function __construct(
        public string $paymentId,
        public string $merchantId,
        public int $amount,
        public string $currency,
        public string $correlationId,
        public ?string $causationId = null,
        public int $feeAmount = 0,
    ) {}
}
