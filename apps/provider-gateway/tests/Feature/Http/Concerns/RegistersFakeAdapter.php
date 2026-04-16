<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Concerns;

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
use App\Domain\Provider\ProviderAdapterInterface;
use App\Domain\Provider\ProviderRegistryInterface;

/**
 * Registers a fake adapter for the 'fake' provider key so feature tests can
 * exercise the full HTTP → handler → adapter → response cycle without a real PSP.
 */
trait RegistersFakeAdapter
{
    private function registerFakeAdapter(
        ?AuthorizeResponse $authorizeResponse = null,
        ?RefundResponse $refundResponse = null,
        ?StatusQueryResponse $statusQueryResponse = null,
        ?RefundStatusQueryResponse $refundStatusQueryResponse = null,
    ): void {
        $adapter = new class($authorizeResponse ?? new AuthorizeResponse('fake-ref-001', 'captured', false, true), $refundResponse ?? new RefundResponse('fake-ref-002', 'refunded', false), $statusQueryResponse ?? new StatusQueryResponse('captured', true, false, false), $refundStatusQueryResponse ?? new RefundStatusQueryResponse('refunded', true, false)) implements ProviderAdapterInterface
        {
            public function __construct(
                private readonly AuthorizeResponse $authResp,
                private readonly RefundResponse $refundResp,
                private readonly StatusQueryResponse $statusResp,
                private readonly RefundStatusQueryResponse $refundStatusResp,
            ) {}

            public function providerKey(): string
            {
                return 'fake';
            }

            public function authorize(AuthorizeRequest $request): AuthorizeResponse
            {
                return $this->authResp;
            }

            public function capture(CaptureRequest $request): CaptureResponse
            {
                return new CaptureResponse($request->providerReference, 'captured', false);
            }

            public function refund(RefundRequest $request): RefundResponse
            {
                return $this->refundResp;
            }

            public function queryPaymentStatus(StatusQueryRequest $request): StatusQueryResponse
            {
                return $this->statusResp;
            }

            public function queryRefundStatus(RefundStatusQueryRequest $request): RefundStatusQueryResponse
            {
                return $this->refundStatusResp;
            }

            public function parseWebhook(array $payload, array $headers): ParsedWebhookEvent
            {
                return new ParsedWebhookEvent('evt-1', 'fake-ref-001', 'payment.captured', 'captured', 'CAPTURED', $payload);
            }

            public function mapStatus(string $rawStatus): string
            {
                return 'captured';
            }
        };

        $this->app->make(ProviderRegistryInterface::class)->register($adapter);
    }
}
