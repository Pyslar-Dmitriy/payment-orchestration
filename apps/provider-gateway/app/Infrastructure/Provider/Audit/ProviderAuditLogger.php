<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Audit;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Persists provider adapter call audit records to the database.
 */
final class ProviderAuditLogger implements ProviderAuditLoggerInterface
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
    ): void {
        ProviderAuditLog::create([
            'id' => Str::uuid()->toString(),
            'provider_key' => $providerKey,
            'operation' => $operation,
            'payment_uuid' => $paymentUuid,
            'refund_uuid' => $refundUuid,
            'correlation_id' => $correlationId,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'outcome' => $outcome,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'requested_at' => $requestedAt,
            'responded_at' => $respondedAt,
            'created_at' => $respondedAt,
        ]);
    }
}
