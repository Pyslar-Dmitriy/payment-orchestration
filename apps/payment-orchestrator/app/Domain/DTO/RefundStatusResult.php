<?php

declare(strict_types=1);

namespace App\Domain\DTO;

/**
 * Returned by ProviderStatusQueryActivity::queryRefundStatus() during timeout recovery.
 * isRefunded and isFailed are mutually exclusive; both false means status is unknown/pending.
 */
final class RefundStatusResult
{
    public function __construct(
        public readonly string $providerStatus,
        public readonly bool $isRefunded,
        public readonly bool $isFailed,
    ) {}
}
