<?php

namespace App\Infrastructure\PaymentDomain;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class PaymentDomainClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.payment_domain.base_url'), '/');
    }

    /**
     * @param array{
     *   merchant_id: string,
     *   amount: int,
     *   currency: string,
     *   external_reference: string,
     *   customer_reference: string|null,
     *   payment_method_reference: string|null,
     *   metadata: array|null,
     *   correlation_id: string,
     * } $payload
     * @return array{payment_id: string, status: string}
     *
     * @throws RequestException
     */
    public function initiatePayment(array $payload): array
    {
        $response = Http::withHeaders([
            'X-Correlation-ID' => $payload['correlation_id'],
            'Accept' => 'application/json',
        ])->post("{$this->baseUrl}/api/v1/payments", $payload);

        $response->throw();

        return $response->json();
    }

    /**
     * @return array{
     *   payment_id: string,
     *   status: string,
     *   amount: int,
     *   currency: string,
     *   provider_reference: string|null,
     *   failure_reason: string|null,
     *   created_at: string,
     *   updated_at: string,
     * }|null
     *
     * @throws RequestException
     */
    public function getPayment(string $paymentId, string $merchantId, string $correlationId): ?array
    {
        $response = Http::withHeaders([
            'X-Correlation-ID' => $correlationId,
            'Accept' => 'application/json',
        ])->get("{$this->baseUrl}/api/v1/payments/{$paymentId}", [
            'merchant_id' => $merchantId,
        ]);

        if ($response->notFound()) {
            return null;
        }

        $response->throw();

        return $response->json();
    }
}
