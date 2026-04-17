<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Audit;

use App\Domain\Provider\DTO\AuthorizeRequest;
use App\Domain\Provider\DTO\AuthorizeResponse;
use App\Domain\Provider\DTO\CaptureRequest;
use App\Domain\Provider\DTO\CaptureResponse;
use App\Domain\Provider\DTO\ParsedWebhookEvent;
use App\Domain\Provider\DTO\RefundRequest;
use App\Domain\Provider\DTO\RefundResponse;
use App\Domain\Provider\DTO\RefundStatusQueryRequest;
use App\Domain\Provider\DTO\RefundStatusQueryResponse;
use App\Domain\Provider\DTO\StatusQueryRequest;
use App\Domain\Provider\DTO\StatusQueryResponse;
use App\Domain\Provider\Exception\ProviderHardFailureException;
use App\Domain\Provider\Exception\ProviderTransientException;
use App\Domain\Provider\ProviderAdapterInterface;
use Carbon\Carbon;

/**
 * Transparent decorator that records every provider call to the audit log.
 *
 * Wraps any ProviderAdapterInterface and captures:
 * - request payload and timestamps
 * - response payload and latency
 * - failure details on ProviderHardFailureException or ProviderTransientException
 *
 * Methods that do not involve provider I/O (parseWebhook, mapStatus) are
 * delegated without auditing.
 */
final class AuditingProviderAdapter implements ProviderAdapterInterface
{
    public function __construct(
        private readonly ProviderAdapterInterface $inner,
        private readonly ProviderAuditLoggerInterface $logger,
    ) {}

    public function providerKey(): string
    {
        return $this->inner->providerKey();
    }

    public function authorize(AuthorizeRequest $request): AuthorizeResponse
    {
        $requestPayload = [
            'payment_uuid' => $request->paymentUuid,
            'correlation_id' => $request->correlationId,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'country' => $request->country,
        ];

        $requestedAt = Carbon::now();
        [$response, $error] = $this->call(fn () => $this->inner->authorize($request));
        $respondedAt = Carbon::now();

        $responsePayload = $response !== null ? [
            'provider_reference' => $response->providerReference,
            'provider_status' => $response->providerStatus,
            'is_async' => $response->isAsync,
            'is_captured' => $response->isCaptured,
        ] : null;

        $this->audit(
            operation: 'authorize',
            paymentUuid: $request->paymentUuid,
            refundUuid: null,
            correlationId: $request->correlationId,
            requestPayload: $requestPayload,
            responsePayload: $responsePayload,
            error: $error,
            requestedAt: $requestedAt,
            respondedAt: $respondedAt,
        );

        if ($error !== null) {
            throw $error;
        }

        /** @var AuthorizeResponse $response */
        return $response;
    }

    public function capture(CaptureRequest $request): CaptureResponse
    {
        $requestPayload = [
            'payment_uuid' => $request->paymentUuid,
            'provider_reference' => $request->providerReference,
            'correlation_id' => $request->correlationId,
            'amount' => $request->amount,
            'currency' => $request->currency,
        ];

        $requestedAt = Carbon::now();
        [$response, $error] = $this->call(fn () => $this->inner->capture($request));
        $respondedAt = Carbon::now();

        $responsePayload = $response !== null ? [
            'provider_reference' => $response->providerReference,
            'provider_status' => $response->providerStatus,
            'is_async' => $response->isAsync,
        ] : null;

        $this->audit(
            operation: 'capture',
            paymentUuid: $request->paymentUuid,
            refundUuid: null,
            correlationId: $request->correlationId,
            requestPayload: $requestPayload,
            responsePayload: $responsePayload,
            error: $error,
            requestedAt: $requestedAt,
            respondedAt: $respondedAt,
        );

        if ($error !== null) {
            throw $error;
        }

        /** @var CaptureResponse $response */
        return $response;
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $requestPayload = [
            'refund_uuid' => $request->refundUuid,
            'payment_uuid' => $request->paymentUuid,
            'provider_reference' => $request->providerReference,
            'correlation_id' => $request->correlationId,
            'amount' => $request->amount,
            'currency' => $request->currency,
        ];

        $requestedAt = Carbon::now();
        [$response, $error] = $this->call(fn () => $this->inner->refund($request));
        $respondedAt = Carbon::now();

        $responsePayload = $response !== null ? [
            'provider_reference' => $response->providerReference,
            'provider_status' => $response->providerStatus,
            'is_async' => $response->isAsync,
        ] : null;

        $this->audit(
            operation: 'refund',
            paymentUuid: $request->paymentUuid,
            refundUuid: $request->refundUuid,
            correlationId: $request->correlationId,
            requestPayload: $requestPayload,
            responsePayload: $responsePayload,
            error: $error,
            requestedAt: $requestedAt,
            respondedAt: $respondedAt,
        );

        if ($error !== null) {
            throw $error;
        }

        /** @var RefundResponse $response */
        return $response;
    }

    public function queryPaymentStatus(StatusQueryRequest $request): StatusQueryResponse
    {
        $requestPayload = [
            'payment_uuid' => $request->paymentUuid,
            'correlation_id' => $request->correlationId,
            'provider_reference' => $request->providerReference,
        ];

        $requestedAt = Carbon::now();
        [$response, $error] = $this->call(fn () => $this->inner->queryPaymentStatus($request));
        $respondedAt = Carbon::now();

        $responsePayload = $response !== null ? [
            'provider_status' => $response->providerStatus,
            'is_captured' => $response->isCaptured,
            'is_authorized' => $response->isAuthorized,
            'is_failed' => $response->isFailed,
        ] : null;

        $this->audit(
            operation: 'query_payment_status',
            paymentUuid: $request->paymentUuid,
            refundUuid: null,
            correlationId: $request->correlationId,
            requestPayload: $requestPayload,
            responsePayload: $responsePayload,
            error: $error,
            requestedAt: $requestedAt,
            respondedAt: $respondedAt,
        );

        if ($error !== null) {
            throw $error;
        }

        /** @var StatusQueryResponse $response */
        return $response;
    }

    public function queryRefundStatus(RefundStatusQueryRequest $request): RefundStatusQueryResponse
    {
        $requestPayload = [
            'refund_uuid' => $request->refundUuid,
            'correlation_id' => $request->correlationId,
            'provider_reference' => $request->providerReference,
        ];

        $requestedAt = Carbon::now();
        [$response, $error] = $this->call(fn () => $this->inner->queryRefundStatus($request));
        $respondedAt = Carbon::now();

        $responsePayload = $response !== null ? [
            'provider_status' => $response->providerStatus,
            'is_refunded' => $response->isRefunded,
            'is_failed' => $response->isFailed,
        ] : null;

        $this->audit(
            operation: 'query_refund_status',
            paymentUuid: null,
            refundUuid: $request->refundUuid,
            correlationId: $request->correlationId,
            requestPayload: $requestPayload,
            responsePayload: $responsePayload,
            error: $error,
            requestedAt: $requestedAt,
            respondedAt: $respondedAt,
        );

        if ($error !== null) {
            throw $error;
        }

        /** @var RefundStatusQueryResponse $response */
        return $response;
    }

    public function parseWebhook(array $payload, array $headers): ParsedWebhookEvent
    {
        return $this->inner->parseWebhook($payload, $headers);
    }

    public function mapStatus(string $rawStatus): string
    {
        return $this->inner->mapStatus($rawStatus);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Executes a provider call, returning [result, exception].
     * Exactly one of the two values will be non-null.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return array{T|null, \Throwable|null}
     */
    private function call(callable $fn): array
    {
        try {
            return [$fn(), null];
        } catch (\Throwable $e) {
            return [null, $e];
        }
    }

    private function audit(
        string $operation,
        ?string $paymentUuid,
        ?string $refundUuid,
        string $correlationId,
        array $requestPayload,
        ?array $responsePayload,
        ?\Throwable $error,
        Carbon $requestedAt,
        Carbon $respondedAt,
    ): void {
        $durationMs = (int) ($requestedAt->diffInMilliseconds($respondedAt, true));

        [$outcome, $errorCode, $errorMessage] = $this->classifyError($error);

        $this->logger->record(
            providerKey: $this->inner->providerKey(),
            operation: $operation,
            paymentUuid: $paymentUuid,
            refundUuid: $refundUuid,
            correlationId: $correlationId,
            requestPayload: $requestPayload,
            responsePayload: $responsePayload,
            outcome: $outcome,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            durationMs: $durationMs,
            requestedAt: $requestedAt,
            respondedAt: $respondedAt,
        );
    }

    /**
     * @return array{string, string|null, string|null}
     */
    private function classifyError(?\Throwable $error): array
    {
        if ($error === null) {
            return ['success', null, null];
        }

        if ($error instanceof ProviderHardFailureException) {
            return ['hard_failure', $error->providerCode ?: null, $error->getMessage()];
        }

        if ($error instanceof ProviderTransientException) {
            return ['transient_failure', null, $error->getMessage()];
        }

        return ['exception', null, $error->getMessage()];
    }
}
