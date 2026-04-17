<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Provider\Audit;

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
use App\Infrastructure\Provider\Audit\AuditingProviderAdapter;
use App\Infrastructure\Provider\Audit\ProviderAuditLoggerInterface;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class AuditingProviderAdapterTest extends TestCase
{
    private string $paymentUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private string $refundUuid = 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff';

    private string $correlationId = 'cccccccc-dddd-eeee-ffff-000000000000';

    // ── providerKey delegation ────────────────────────────────────────────────

    public function test_provider_key_is_delegated_to_inner(): void
    {
        $inner = $this->makeInner('my-provider');
        $adapter = new AuditingProviderAdapter($inner, $this->makeLogger());

        $this->assertSame('my-provider', $adapter->providerKey());
    }

    // ── authorize: success ────────────────────────────────────────────────────

    public function test_authorize_returns_inner_response_on_success(): void
    {
        $expected = new AuthorizeResponse('ref-001', 'captured', false, true);
        $inner = $this->makeInner(authorizeResponse: $expected);
        $recorded = [];
        $logger = $this->makeLogger($recorded);

        $adapter = new AuditingProviderAdapter($inner, $logger);
        $result = $adapter->authorize(new AuthorizeRequest($this->paymentUuid, $this->correlationId));

        $this->assertSame($expected, $result);
        $this->assertCount(1, $recorded);
        $this->assertSame('authorize', $recorded[0]['operation']);
        $this->assertSame('success', $recorded[0]['outcome']);
        $this->assertNull($recorded[0]['error_code']);
        $this->assertSame($this->paymentUuid, $recorded[0]['request_payload']['payment_uuid']);
        $this->assertSame('captured', $recorded[0]['response_payload']['provider_status']);
    }

    // ── authorize: hard failure ───────────────────────────────────────────────

    public function test_authorize_re_throws_hard_failure_and_logs_it(): void
    {
        $exception = new ProviderHardFailureException('Declined', 'do_not_honor');
        $inner = $this->makeInnerThatThrows($exception);
        $recorded = [];
        $logger = $this->makeLogger($recorded);

        $adapter = new AuditingProviderAdapter($inner, $logger);

        $this->expectException(ProviderHardFailureException::class);
        try {
            $adapter->authorize(new AuthorizeRequest($this->paymentUuid, $this->correlationId));
        } finally {
            $this->assertCount(1, $recorded);
            $this->assertSame('hard_failure', $recorded[0]['outcome']);
            $this->assertSame('do_not_honor', $recorded[0]['error_code']);
            $this->assertSame('Declined', $recorded[0]['error_message']);
            $this->assertNull($recorded[0]['response_payload']);
        }
    }

    // ── authorize: transient failure ─────────────────────────────────────────

    public function test_authorize_re_throws_transient_failure_and_logs_it(): void
    {
        $exception = new ProviderTransientException('Timeout');
        $inner = $this->makeInnerThatThrows($exception);
        $recorded = [];
        $logger = $this->makeLogger($recorded);

        $adapter = new AuditingProviderAdapter($inner, $logger);

        $this->expectException(ProviderTransientException::class);
        try {
            $adapter->authorize(new AuthorizeRequest($this->paymentUuid, $this->correlationId));
        } finally {
            $this->assertCount(1, $recorded);
            $this->assertSame('transient_failure', $recorded[0]['outcome']);
            $this->assertNull($recorded[0]['error_code']);
            $this->assertSame('Timeout', $recorded[0]['error_message']);
        }
    }

    // ── capture ───────────────────────────────────────────────────────────────

    public function test_capture_records_audit_log(): void
    {
        $inner = $this->makeInner();
        $recorded = [];
        $logger = $this->makeLogger($recorded);

        $adapter = new AuditingProviderAdapter($inner, $logger);
        $adapter->capture(new CaptureRequest($this->paymentUuid, 'psp-ref', $this->correlationId, 1000, 'USD'));

        $this->assertCount(1, $recorded);
        $this->assertSame('capture', $recorded[0]['operation']);
        $this->assertSame('success', $recorded[0]['outcome']);
        $this->assertSame('psp-ref', $recorded[0]['request_payload']['provider_reference']);
    }

    // ── refund ────────────────────────────────────────────────────────────────

    public function test_refund_records_payment_and_refund_uuid(): void
    {
        $inner = $this->makeInner();
        $recorded = [];
        $logger = $this->makeLogger($recorded);

        $adapter = new AuditingProviderAdapter($inner, $logger);
        $adapter->refund(new RefundRequest($this->refundUuid, $this->paymentUuid, 'psp-ref', $this->correlationId));

        $this->assertCount(1, $recorded);
        $this->assertSame('refund', $recorded[0]['operation']);
        $this->assertSame($this->paymentUuid, $recorded[0]['payment_uuid']);
        $this->assertSame($this->refundUuid, $recorded[0]['refund_uuid']);
    }

    // ── queryPaymentStatus ────────────────────────────────────────────────────

    public function test_query_payment_status_records_audit_log(): void
    {
        $inner = $this->makeInner();
        $recorded = [];
        $logger = $this->makeLogger($recorded);

        $adapter = new AuditingProviderAdapter($inner, $logger);
        $adapter->queryPaymentStatus(new StatusQueryRequest($this->paymentUuid, $this->correlationId));

        $this->assertCount(1, $recorded);
        $this->assertSame('query_payment_status', $recorded[0]['operation']);
        $this->assertSame($this->paymentUuid, $recorded[0]['payment_uuid']);
        $this->assertNull($recorded[0]['refund_uuid']);
    }

    // ── queryRefundStatus ─────────────────────────────────────────────────────

    public function test_query_refund_status_records_refund_uuid_and_no_payment_uuid(): void
    {
        $inner = $this->makeInner();
        $recorded = [];
        $logger = $this->makeLogger($recorded);

        $adapter = new AuditingProviderAdapter($inner, $logger);
        $adapter->queryRefundStatus(new RefundStatusQueryRequest($this->refundUuid, $this->correlationId));

        $this->assertCount(1, $recorded);
        $this->assertSame('query_refund_status', $recorded[0]['operation']);
        $this->assertNull($recorded[0]['payment_uuid']);
        $this->assertSame($this->refundUuid, $recorded[0]['refund_uuid']);
    }

    // ── non-audited methods ───────────────────────────────────────────────────

    public function test_parse_webhook_delegates_without_audit_record(): void
    {
        $inner = $this->makeInner();
        $recorded = [];
        $logger = $this->makeLogger($recorded);

        $adapter = new AuditingProviderAdapter($inner, $logger);
        $adapter->parseWebhook(['event_id' => 'e1', 'payment_reference' => 'r1', 'event_type' => 'payment.captured', 'status' => 'CAPTURED'], []);

        $this->assertCount(0, $recorded);
    }

    public function test_map_status_delegates_without_audit_record(): void
    {
        $inner = $this->makeInner();
        $recorded = [];
        $logger = $this->makeLogger($recorded);

        $adapter = new AuditingProviderAdapter($inner, $logger);
        $adapter->mapStatus('CAPTURED');

        $this->assertCount(0, $recorded);
    }

    // ── timing ────────────────────────────────────────────────────────────────

    public function test_duration_ms_is_non_negative(): void
    {
        $inner = $this->makeInner();
        $recorded = [];
        $logger = $this->makeLogger($recorded);

        $adapter = new AuditingProviderAdapter($inner, $logger);
        $adapter->authorize(new AuthorizeRequest($this->paymentUuid, $this->correlationId));

        $this->assertGreaterThanOrEqual(0, $recorded[0]['duration_ms']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeInner(
        string $providerKey = 'test',
        ?AuthorizeResponse $authorizeResponse = null,
    ): ProviderAdapterInterface {
        $authResp = $authorizeResponse ?? new AuthorizeResponse('ref-001', 'captured', false, true);

        return new class($providerKey, $authResp) implements ProviderAdapterInterface
        {
            public function __construct(
                private readonly string $key,
                private readonly AuthorizeResponse $authResp,
            ) {}

            public function providerKey(): string
            {
                return $this->key;
            }

            public function authorize(AuthorizeRequest $r): AuthorizeResponse
            {
                return $this->authResp;
            }

            public function capture(CaptureRequest $r): CaptureResponse
            {
                return new CaptureResponse($r->providerReference, 'captured', false);
            }

            public function refund(RefundRequest $r): RefundResponse
            {
                return new RefundResponse('ref-refund', 'refunded', false);
            }

            public function queryPaymentStatus(StatusQueryRequest $r): StatusQueryResponse
            {
                return new StatusQueryResponse('captured', true, false, false);
            }

            public function queryRefundStatus(RefundStatusQueryRequest $r): RefundStatusQueryResponse
            {
                return new RefundStatusQueryResponse('refunded', true, false);
            }

            public function parseWebhook(array $p, array $h): ParsedWebhookEvent
            {
                return new ParsedWebhookEvent($p['event_id'], $p['payment_reference'], $p['event_type'], 'captured', $p['status'], $p);
            }

            public function mapStatus(string $s): string
            {
                return 'captured';
            }
        };
    }

    private function makeInnerThatThrows(\Throwable $exception): ProviderAdapterInterface
    {
        return new class($exception) implements ProviderAdapterInterface
        {
            public function __construct(private readonly \Throwable $e) {}

            public function providerKey(): string
            {
                return 'test';
            }

            public function authorize(AuthorizeRequest $r): AuthorizeResponse
            {
                throw $this->e;
            }

            public function capture(CaptureRequest $r): CaptureResponse
            {
                throw $this->e;
            }

            public function refund(RefundRequest $r): RefundResponse
            {
                throw $this->e;
            }

            public function queryPaymentStatus(StatusQueryRequest $r): StatusQueryResponse
            {
                throw $this->e;
            }

            public function queryRefundStatus(RefundStatusQueryRequest $r): RefundStatusQueryResponse
            {
                throw $this->e;
            }

            public function parseWebhook(array $p, array $h): ParsedWebhookEvent
            {
                throw $this->e;
            }

            public function mapStatus(string $s): string
            {
                throw $this->e;
            }
        };
    }

    /**
     * Returns a ProviderAuditLoggerInterface spy that appends recorded call data
     * to $recorded instead of hitting the database.
     *
     * @param  array<int, array<string, mixed>>  $recorded
     */
    private function makeLogger(array &$recorded = []): ProviderAuditLoggerInterface
    {
        $ref = &$recorded;

        return new class($ref) implements ProviderAuditLoggerInterface
        {
            /** @param array<int, array<string, mixed>> $recorded */
            public function __construct(private array &$recorded) {}

            public function record(
                string $providerKey,
                string $operation,
                ?string $paymentUuid,
                ?string $refundUuid,
                string $correlationId,
                array $requestPayload,
                ?array $responsePayload,
                string $outcome,
                ?string $errorCode,
                ?string $errorMessage,
                int $durationMs,
                Carbon $requestedAt,
                Carbon $respondedAt,
            ): void {
                $this->recorded[] = [
                    'provider_key' => $providerKey,
                    'operation' => $operation,
                    'payment_uuid' => $paymentUuid,
                    'refund_uuid' => $refundUuid,
                    'correlation_id' => $correlationId,
                    'request_payload' => $requestPayload,
                    'response_payload' => $responsePayload,
                    'outcome' => $outcome,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                    'duration_ms' => $durationMs,
                    'requested_at' => $requestedAt,
                    'responded_at' => $respondedAt,
                ];
            }
        };
    }
}
