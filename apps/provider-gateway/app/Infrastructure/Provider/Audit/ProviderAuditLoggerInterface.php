<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Audit;

use Carbon\Carbon;

interface ProviderAuditLoggerInterface
{
    public function record(
        string $providerKey,
        string $operation,
        ?string $paymentUuid,
        ?string $refundUuid,
        string $correlationId,
        array $requestPayload,
        ?array $responsePayload,
        string $outcome,
        ?string $errorCode,
        ?string $errorMessage,
        int $durationMs,
        Carbon $requestedAt,
        Carbon $respondedAt,
    ): void;
}
