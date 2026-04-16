<?php

declare(strict_types=1);

namespace App\Application\Provider;

use App\Domain\Provider\DTO\StatusQueryRequest;
use App\Domain\Provider\ProviderRegistryInterface;
use Illuminate\Support\Facades\Log;

final class QueryPaymentStatusHandler
{
    public function __construct(private readonly ProviderRegistryInterface $registry) {}

    /**
     * @return array{provider_status: string, is_captured: bool, is_authorized: bool, is_failed: bool}
     */
    public function handle(
        string $paymentUuid,
        string $providerKey,
        string $correlationId,
        ?string $providerReference = null,
    ): array {
        $adapter = $this->registry->get($providerKey);

        Log::info('Querying payment status from provider adapter', [
            'payment_uuid' => $paymentUuid,
            'provider_key' => $providerKey,
            'correlation_id' => $correlationId,
        ]);

        $response = $adapter->queryPaymentStatus(new StatusQueryRequest(
            paymentUuid: $paymentUuid,
            correlationId: $correlationId,
            providerReference: $providerReference,
        ));

        return [
            'provider_status' => $response->providerStatus,
            'is_captured' => $response->isCaptured,
            'is_authorized' => $response->isAuthorized,
            'is_failed' => $response->isFailed,
        ];
    }
}
