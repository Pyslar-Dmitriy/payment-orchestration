<?php

declare(strict_types=1);

namespace App\Application\Provider;

use App\Domain\Provider\DTO\AuthorizeRequest;
use App\Domain\Provider\DTO\CaptureRequest;
use App\Domain\Provider\ProviderRegistryInterface;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the authorize → capture sequence for a given provider.
 *
 * If the PSP captures atomically (AuthorizeResponse::$isCaptured = true),
 * the capture step is skipped. If the PSP is asynchronous, the handler
 * returns immediately and the final result arrives via webhook.
 */
final class AuthorizeAndCaptureHandler
{
    public function __construct(private readonly ProviderRegistryInterface $registry) {}

    /**
     * @return array{provider_reference: string, provider_status: string, is_async: bool}
     */
    public function handle(
        string $paymentUuid,
        string $providerKey,
        string $correlationId,
        int $amount = 0,
        string $currency = '',
        string $country = '',
    ): array {
        $adapter = $this->registry->get($providerKey);

        Log::info('Sending authorize request to provider adapter', [
            'payment_uuid' => $paymentUuid,
            'provider_key' => $providerKey,
            'correlation_id' => $correlationId,
        ]);

        $authResponse = $adapter->authorize(new AuthorizeRequest(
            paymentUuid: $paymentUuid,
            correlationId: $correlationId,
            amount: $amount,
            currency: $currency,
            country: $country,
        ));

        if ($authResponse->isAsync) {
            return [
                'provider_reference' => $authResponse->providerReference,
                'provider_status' => $authResponse->providerStatus,
                'is_async' => true,
            ];
        }

        if ($authResponse->isCaptured) {
            return [
                'provider_reference' => $authResponse->providerReference,
                'provider_status' => $authResponse->providerStatus,
                'is_async' => false,
            ];
        }

        // PSP returned a synchronous authorization — proceed to capture immediately.
        Log::info('Authorization complete, sending capture request', [
            'payment_uuid' => $paymentUuid,
            'provider_key' => $providerKey,
            'correlation_id' => $correlationId,
        ]);

        $captureResponse = $adapter->capture(new CaptureRequest(
            paymentUuid: $paymentUuid,
            providerReference: $authResponse->providerReference,
            correlationId: $correlationId,
            amount: $amount,
            currency: $currency,
        ));

        return [
            'provider_reference' => $captureResponse->providerReference,
            'provider_status' => $captureResponse->providerStatus,
            'is_async' => $captureResponse->isAsync,
        ];
    }
}
