<?php

declare(strict_types=1);

namespace App\Application\Provider;

use App\Domain\Provider\DTO\RefundStatusQueryRequest;
use App\Domain\Provider\ProviderRegistryInterface;
use Illuminate\Support\Facades\Log;

final class QueryRefundStatusHandler
{
    public function __construct(private readonly ProviderRegistryInterface $registry) {}

    /**
     * @return array{provider_status: string, is_refunded: bool, is_failed: bool}
     */
    public function handle(
        string $refundUuid,
        string $providerKey,
        string $correlationId,
        ?string $providerReference = null,
    ): array {
        $adapter = $this->registry->get($providerKey);

        Log::info('Querying refund status from provider adapter', [
            'refund_uuid' => $refundUuid,
            'provider_key' => $providerKey,
            'correlation_id' => $correlationId,
        ]);

        $response = $adapter->queryRefundStatus(new RefundStatusQueryRequest(
            refundUuid: $refundUuid,
            correlationId: $correlationId,
            providerReference: $providerReference,
        ));

        return [
            'provider_status' => $response->providerStatus,
            'is_refunded' => $response->isRefunded,
            'is_failed' => $response->isFailed,
        ];
    }
}
