<?php

declare(strict_types=1);

namespace App\Domain\Provider\DTO;

final class StatusQueryRequest
{
    /**
     * @param  string|null  $providerReference  PSP transaction reference. Required by real PSPs;
     *                                          null is acceptable for mock adapters or when the
     *                                          audit log lookup (TASK-072) has not resolved it yet.
     */
    public function __construct(
        public readonly string $paymentUuid,
        public readonly string $correlationId,
        public readonly ?string $providerReference = null,
    ) {}
}
