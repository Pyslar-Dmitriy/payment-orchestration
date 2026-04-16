<?php

declare(strict_types=1);

namespace App\Domain\Provider;

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

/**
 * Unified PSP integration contract.
 *
 * Each PSP adapter implements this interface. Business logic in the Application
 * layer calls these methods; PSP-specific wire format, auth, and URL are
 * encapsulated entirely within the adapter.
 *
 * All money amounts are in the smallest currency unit (e.g. cents).
 *
 * @throws ProviderHardFailureException for non-retryable PSP rejections.
 * @throws ProviderTransientException for retryable connectivity/timeout issues.
 */
interface ProviderAdapterInterface
{
    /**
     * Returns the unique provider key this adapter handles (e.g. 'mock', 'stripe').
     */
    public function providerKey(): string;

    /**
     * Submits an authorization request to the PSP.
     *
     * If the PSP captures atomically (no separate capture step), the returned
     * AuthorizeResponse::$providerStatus will be 'captured' and
     * AuthorizeResponse::$isCaptured will be true.
     */
    public function authorize(AuthorizeRequest $request): AuthorizeResponse;

    /**
     * Captures a previously authorized payment.
     *
     * Only called when authorize() returned AuthorizeResponse::$isCaptured = false.
     */
    public function capture(CaptureRequest $request): CaptureResponse;

    /**
     * Submits a refund request to the PSP.
     */
    public function refund(RefundRequest $request): RefundResponse;

    /**
     * Queries the PSP for the current status of a payment.
     * Used during the webhook timeout recovery path.
     */
    public function queryPaymentStatus(StatusQueryRequest $request): StatusQueryResponse;

    /**
     * Queries the PSP for the current status of a refund.
     */
    public function queryRefundStatus(RefundStatusQueryRequest $request): RefundStatusQueryResponse;

    /**
     * Parses a raw PSP webhook payload into a normalized event.
     * Called by the webhook-normalizer for provider-specific parsing logic.
     */
    public function parseWebhook(array $payload, array $headers): ParsedWebhookEvent;

    /**
     * Maps a raw PSP-specific status string to an internal status vocabulary.
     * Valid internal statuses: authorized, captured, failed, refunded.
     */
    public function mapStatus(string $rawStatus): string;
}
