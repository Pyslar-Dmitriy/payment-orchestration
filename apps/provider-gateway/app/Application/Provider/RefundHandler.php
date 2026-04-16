<?php

declare(strict_types=1);

namespace App\Application\Provider;

use App\Domain\Provider\DTO\RefundRequest;
use App\Domain\Provider\ProviderRegistryInterface;
use Illuminate\Support\Facades\Log;

final class RefundHandler
{
    public function __construct(private readonly ProviderRegistryInterface $registry) {}

    /**
     * @return array{provider_reference: string, provider_status: string, is_async: bool}
     */
    public function handle(
        string $refundUuid,
        string $paymentUuid,
        string $providerKey,
        string $correlationId,
        string $providerReference = '',
        int $amount = 0,
        string $currency = '',
    ): array {
        $adapter = $this->registry->get($providerKey);

        Log::info('Sending refund request to provider adapter', [
            'refund_uuid' => $refundUuid,
            'payment_uuid' => $paymentUuid,
            'provider_key' => $providerKey,
            'correlation_id' => $correlationId,
        ]);

        $response = $adapter->refund(new RefundRequest(
            refundUuid: $refundUuid,
            paymentUuid: $paymentUuid,
            providerReference: $providerReference,
            correlationId: $correlationId,
            amount: $amount,
            currency: $currency,
        ));

        return [
            'provider_reference' => $response->providerReference,
            'provider_status' => $response->providerStatus,
            'is_async' => $response->isAsync,
        ];
    }
}
