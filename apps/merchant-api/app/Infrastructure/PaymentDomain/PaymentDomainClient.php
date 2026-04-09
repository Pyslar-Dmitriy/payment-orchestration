<?php

namespace App\Infrastructure\PaymentDomain;

use App\Infrastructure\PaymentDomain\Exceptions\PaymentDomainCircuitOpenException;
use App\Infrastructure\PaymentDomain\Exceptions\PaymentDomainConflictException;
use App\Infrastructure\PaymentDomain\Exceptions\PaymentDomainTimeoutException;
use App\Infrastructure\PaymentDomain\Exceptions\PaymentDomainUnavailableException;
use App\Infrastructure\PaymentDomain\Exceptions\PaymentDomainValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Response as HttpCodes;
use Illuminate\Support\Facades\Http;

final class PaymentDomainClient
{
    private string $baseUrl;

    public function __construct(private readonly CircuitBreaker $circuitBreaker)
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
     * @throws PaymentDomainCircuitOpenException
     * @throws PaymentDomainTimeoutException
     * @throws PaymentDomainValidationException
     * @throws PaymentDomainConflictException
     * @throws PaymentDomainUnavailableException
     */
    public function initiatePayment(array $payload): array
    {
        $response = $this->send(
            fn (PendingRequest $client) => $client->post("{$this->baseUrl}/api/v1/payments", $payload),
            $payload['correlation_id']
        );

        return $response->json();
    }

    /**
     * @param array{
     *   merchant_id: string,
     *   payment_id: string,
     *   amount: int,
     *   correlation_id: string,
     * } $payload
     * @return array{refund_id: string, payment_id: string, status: string, amount: int, currency: string}|null
     *
     * @throws PaymentDomainCircuitOpenException
     * @throws PaymentDomainTimeoutException
     * @throws PaymentDomainValidationException
     * @throws PaymentDomainConflictException
     * @throws PaymentDomainUnavailableException
     */
    public function initiateRefund(array $payload): ?array
    {
        $response = $this->send(
            fn (PendingRequest $client) => $client->post("{$this->baseUrl}/api/v1/refunds", $payload),
            $payload['correlation_id'],
            allowNotFound: true
        );

        if ($response === null) {
            return null;
        }

        return $response->json();
    }

    /**
     * @return array{
     *   refund_id: string,
     *   payment_id: string,
     *   status: string,
     *   amount: int,
     *   currency: string,
     *   correlation_id: string,
     *   created_at: string,
     *   updated_at: string,
     * }|null
     *
     * @throws PaymentDomainCircuitOpenException
     * @throws PaymentDomainTimeoutException
     * @throws PaymentDomainUnavailableException
     */
    public function getRefund(string $refundId, string $merchantId, string $correlationId): ?array
    {
        $response = $this->send(
            fn (PendingRequest $client) => $client->get("{$this->baseUrl}/api/v1/refunds/{$refundId}", [
                'merchant_id' => $merchantId,
            ]),
            $correlationId,
            allowNotFound: true
        );

        if ($response === null) {
            return null;
        }

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
     * @throws PaymentDomainCircuitOpenException
     * @throws PaymentDomainTimeoutException
     * @throws PaymentDomainUnavailableException
     */
    public function getPayment(string $paymentId, string $merchantId, string $correlationId): ?array
    {
        $response = $this->send(
            fn (PendingRequest $client) => $client->get("{$this->baseUrl}/api/v1/payments/{$paymentId}", [
                'merchant_id' => $merchantId,
            ]),
            $correlationId,
            allowNotFound: true
        );

        if ($response === null) {
            return null;
        }

        return $response->json();
    }

    /**
     * Executes an HTTP call with timeouts, a single retry on connection errors, and circuit breaker protection.
     * Returns null only when $allowNotFound is true and the response is 404.
     *
     * @throws PaymentDomainCircuitOpenException
     * @throws PaymentDomainTimeoutException
     * @throws PaymentDomainValidationException
     * @throws PaymentDomainConflictException
     * @throws PaymentDomainUnavailableException
     */
    private function send(callable $request, string $correlationId, bool $allowNotFound = false): ?Response
    {
        if ($this->circuitBreaker->isOpen()) {
            throw new PaymentDomainCircuitOpenException('Payment domain circuit is open.');
        }

        $client = Http::connectTimeout(
            (int) config('services.payment_domain.connect_timeout', 2)
        )->timeout(
            (int) config('services.payment_domain.timeout', 5)
        )->retry(
            times: 2,
            sleepMilliseconds: 0,
            when: fn ($e) => $e instanceof ConnectionException,
            throw: false
        )->withHeaders([
            'X-Correlation-ID' => $correlationId,
            'Accept' => 'application/json',
        ]);

        // throw: false prevents retry() from calling $response->throw() on non-2xx responses,
        // allowing us to inspect and map them ourselves. ConnectionException re-throws after
        // retries are exhausted, so we catch it here and convert to a domain exception.
        try {
            $response = $request($client);
        } catch (ConnectionException) {
            $this->circuitBreaker->recordFailure();
            throw new PaymentDomainTimeoutException('Payment domain unreachable.');
        }

        /** @var Response $response */
        if ($allowNotFound && $response->notFound()) {
            $this->circuitBreaker->recordSuccess();

            return null;
        }

        if ($response->status() === HttpCodes::HTTP_UNPROCESSABLE_ENTITY) {
            $this->circuitBreaker->recordSuccess();
            throw new PaymentDomainValidationException($response->json() ?? []);
        }

        if ($response->status() === HttpCodes::HTTP_CONFLICT) {
            $this->circuitBreaker->recordSuccess();
            throw new PaymentDomainConflictException($response->json() ?? []);
        }

        if ($response->serverError()) {
            $this->circuitBreaker->recordFailure();
            throw new PaymentDomainUnavailableException("Payment domain returned {$response->status()}.");
        }

        $this->circuitBreaker->recordSuccess();

        return $response;
    }
}
