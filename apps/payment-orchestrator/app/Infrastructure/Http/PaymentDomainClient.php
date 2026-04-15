<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for internal payment-domain service calls.
 * Uses the X-Internal-Secret header for service-to-service authentication.
 */
final class PaymentDomainClient
{
    private string $baseUrl;

    private string $secret;

    private int $connectTimeout;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.payment_domain.base_url'), '/');
        $this->secret = (string) config('services.payment_domain.internal_secret');
        $this->connectTimeout = (int) config('services.payment_domain.connect_timeout', 2);
        $this->timeout = (int) config('services.payment_domain.timeout', 5);
    }

    /**
     * Transitions a payment to a new status.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws \RuntimeException on HTTP failure after retries.
     */
    public function transitionPaymentStatus(string $paymentId, array $payload): void
    {
        $this->send(
            fn ($client) => $client->patch("{$this->baseUrl}/api/internal/v1/payments/{$paymentId}/status", $payload),
        );
    }

    /**
     * Transitions a refund to a new status.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws \RuntimeException on HTTP failure after retries.
     */
    public function transitionRefundStatus(string $refundId, array $payload): void
    {
        $this->send(
            fn ($client) => $client->patch("{$this->baseUrl}/api/internal/v1/refunds/{$refundId}/status", $payload),
        );
    }

    /**
     * @throws \RuntimeException on connection error or non-2xx response.
     */
    private function send(callable $request): Response
    {
        $client = Http::connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->retry(
                times: 2,
                sleepMilliseconds: 0,
                when: fn ($e) => $e instanceof ConnectionException,
                throw: false,
            )
            ->withHeaders([
                'X-Internal-Secret' => $this->secret,
                'Accept' => 'application/json',
            ]);

        try {
            /** @var Response $response */
            $response = $request($client);
        } catch (ConnectionException $e) {
            throw new \RuntimeException(
                "payment-domain unreachable: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->successful()) {
            return $response;
        }

        throw new \RuntimeException(
            "payment-domain returned {$response->status()}: {$response->body()}",
        );
    }
}
