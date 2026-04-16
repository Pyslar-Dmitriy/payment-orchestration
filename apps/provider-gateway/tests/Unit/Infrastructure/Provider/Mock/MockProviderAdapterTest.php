<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Provider\Mock;

use App\Domain\Provider\DTO\AuthorizeRequest;
use App\Domain\Provider\DTO\CaptureRequest;
use App\Domain\Provider\DTO\RefundRequest;
use App\Domain\Provider\DTO\RefundStatusQueryRequest;
use App\Domain\Provider\DTO\StatusQueryRequest;
use App\Domain\Provider\Exception\ProviderHardFailureException;
use App\Domain\Provider\Exception\ProviderTransientException;
use App\Infrastructure\Provider\Mock\Jobs\DeliverMockWebhookJob;
use App\Infrastructure\Provider\Mock\MockProviderAdapter;
use App\Infrastructure\Provider\Mock\MockScenario;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MockProviderAdapterTest extends TestCase
{
    private MockProviderAdapter $adapter;

    private string $paymentUuid = '550e8400-e29b-41d4-a716-446655440000';

    private string $refundUuid = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    private string $correlationId = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new MockProviderAdapter;
        config()->set('mock_provider.scenario', MockScenario::Success->value);
        config()->set('mock_provider.webhook_url', null);
        config()->set('mock_provider.webhook_delay_seconds', 5);
    }

    // ── providerKey ───────────────────────────────────────────────────────────

    public function test_provider_key_is_mock(): void
    {
        $this->assertSame('mock', $this->adapter->providerKey());
    }

    // ── authorize: success ────────────────────────────────────────────────────

    public function test_authorize_success_returns_captured_sync(): void
    {
        $response = $this->adapter->authorize($this->authorizeRequest());

        $this->assertSame('mock-'.$this->paymentUuid, $response->providerReference);
        $this->assertSame('captured', $response->providerStatus);
        $this->assertFalse($response->isAsync);
        $this->assertTrue($response->isCaptured);
    }

    // ── authorize: timeout ────────────────────────────────────────────────────

    public function test_authorize_timeout_throws_transient_exception(): void
    {
        config()->set('mock_provider.scenario', MockScenario::Timeout->value);

        $this->expectException(ProviderTransientException::class);

        $this->adapter->authorize($this->authorizeRequest());
    }

    // ── authorize: hard failure ───────────────────────────────────────────────

    public function test_authorize_hard_failure_throws_hard_failure_exception(): void
    {
        config()->set('mock_provider.scenario', MockScenario::HardFailure->value);

        $this->expectException(ProviderHardFailureException::class);
        $this->expectExceptionMessage('Mock provider declined');

        $this->adapter->authorize($this->authorizeRequest());
    }

    // ── authorize: async_webhook ──────────────────────────────────────────────

    public function test_authorize_async_webhook_returns_pending_async(): void
    {
        Queue::fake();
        config()->set('mock_provider.scenario', MockScenario::AsyncWebhook->value);

        $response = $this->adapter->authorize($this->authorizeRequest());

        $this->assertSame('pending', $response->providerStatus);
        $this->assertTrue($response->isAsync);
        $this->assertFalse($response->isCaptured);
    }

    public function test_authorize_async_webhook_dispatches_one_webhook_job(): void
    {
        Queue::fake();
        config()->set('mock_provider.scenario', MockScenario::AsyncWebhook->value);

        $this->adapter->authorize($this->authorizeRequest());

        Queue::assertPushed(DeliverMockWebhookJob::class, 1);
    }

    // ── authorize: delayed_webhook ────────────────────────────────────────────

    public function test_authorize_delayed_webhook_returns_pending_async(): void
    {
        Queue::fake();
        config()->set('mock_provider.scenario', MockScenario::DelayedWebhook->value);

        $response = $this->adapter->authorize($this->authorizeRequest());

        $this->assertTrue($response->isAsync);
        Queue::assertPushed(DeliverMockWebhookJob::class, 1);
    }

    // ── authorize: duplicate_webhook ──────────────────────────────────────────

    public function test_authorize_duplicate_webhook_dispatches_two_jobs_with_same_event_id(): void
    {
        Queue::fake();
        config()->set('mock_provider.scenario', MockScenario::DuplicateWebhook->value);

        $this->adapter->authorize($this->authorizeRequest());

        Queue::assertPushed(DeliverMockWebhookJob::class, 2);
    }

    public function test_authorize_duplicate_webhook_jobs_have_same_event_id(): void
    {
        Queue::fake();
        config()->set('mock_provider.scenario', MockScenario::DuplicateWebhook->value);

        $this->adapter->authorize($this->authorizeRequest());

        $eventIds = [];
        Queue::assertPushed(DeliverMockWebhookJob::class, function (DeliverMockWebhookJob $job) use (&$eventIds) {
            $eventIds[] = $job->eventId;

            return true;
        });

        $this->assertCount(2, $eventIds);
        $this->assertSame($eventIds[0], $eventIds[1]);
    }

    // ── authorize: out_of_order ───────────────────────────────────────────────

    public function test_authorize_out_of_order_dispatches_two_jobs(): void
    {
        Queue::fake();
        config()->set('mock_provider.scenario', MockScenario::OutOfOrder->value);

        $this->adapter->authorize($this->authorizeRequest());

        Queue::assertPushed(DeliverMockWebhookJob::class, 2);
    }

    public function test_authorize_out_of_order_first_job_is_captured_second_is_authorized(): void
    {
        Queue::fake();
        config()->set('mock_provider.scenario', MockScenario::OutOfOrder->value);

        $this->adapter->authorize($this->authorizeRequest());

        $statuses = [];
        Queue::assertPushed(DeliverMockWebhookJob::class, function (DeliverMockWebhookJob $job) use (&$statuses) {
            $statuses[] = $job->status;

            return true;
        });

        $this->assertCount(2, $statuses);
        $this->assertSame('CAPTURED', $statuses[0]);
        $this->assertSame('AUTHORIZED', $statuses[1]);
    }

    // ── capture ───────────────────────────────────────────────────────────────

    public function test_capture_success_returns_captured(): void
    {
        $response = $this->adapter->capture($this->captureRequest());

        $this->assertSame('mock-ref-001', $response->providerReference);
        $this->assertSame('captured', $response->providerStatus);
        $this->assertFalse($response->isAsync);
    }

    public function test_capture_timeout_throws_transient_exception(): void
    {
        config()->set('mock_provider.scenario', MockScenario::Timeout->value);

        $this->expectException(ProviderTransientException::class);

        $this->adapter->capture($this->captureRequest());
    }

    public function test_capture_hard_failure_throws_hard_failure_exception(): void
    {
        config()->set('mock_provider.scenario', MockScenario::HardFailure->value);

        $this->expectException(ProviderHardFailureException::class);

        $this->adapter->capture($this->captureRequest());
    }

    // ── refund ────────────────────────────────────────────────────────────────

    public function test_refund_success_returns_refunded(): void
    {
        $response = $this->adapter->refund($this->refundRequest());

        $this->assertStringStartsWith('mock-refund-', $response->providerReference);
        $this->assertSame('refunded', $response->providerStatus);
        $this->assertFalse($response->isAsync);
    }

    public function test_refund_timeout_throws_transient_exception(): void
    {
        config()->set('mock_provider.scenario', MockScenario::Timeout->value);

        $this->expectException(ProviderTransientException::class);

        $this->adapter->refund($this->refundRequest());
    }

    public function test_refund_hard_failure_throws_hard_failure_exception(): void
    {
        config()->set('mock_provider.scenario', MockScenario::HardFailure->value);

        $this->expectException(ProviderHardFailureException::class);

        $this->adapter->refund($this->refundRequest());
    }

    // ── queryPaymentStatus ────────────────────────────────────────────────────

    public function test_query_payment_status_success_returns_captured(): void
    {
        $response = $this->adapter->queryPaymentStatus(new StatusQueryRequest($this->paymentUuid, $this->correlationId));

        $this->assertSame('captured', $response->providerStatus);
        $this->assertTrue($response->isCaptured);
        $this->assertFalse($response->isAuthorized);
        $this->assertFalse($response->isFailed);
    }

    public function test_query_payment_status_timeout_throws_transient_exception(): void
    {
        config()->set('mock_provider.scenario', MockScenario::Timeout->value);

        $this->expectException(ProviderTransientException::class);

        $this->adapter->queryPaymentStatus(new StatusQueryRequest($this->paymentUuid, $this->correlationId));
    }

    // ── queryRefundStatus ─────────────────────────────────────────────────────

    public function test_query_refund_status_success_returns_refunded(): void
    {
        $response = $this->adapter->queryRefundStatus(new RefundStatusQueryRequest($this->refundUuid, $this->correlationId));

        $this->assertSame('refunded', $response->providerStatus);
        $this->assertTrue($response->isRefunded);
        $this->assertFalse($response->isFailed);
    }

    public function test_query_refund_status_timeout_throws_transient_exception(): void
    {
        config()->set('mock_provider.scenario', MockScenario::Timeout->value);

        $this->expectException(ProviderTransientException::class);

        $this->adapter->queryRefundStatus(new RefundStatusQueryRequest($this->refundUuid, $this->correlationId));
    }

    // ── parseWebhook ──────────────────────────────────────────────────────────

    public function test_parse_webhook_returns_parsed_event(): void
    {
        $payload = [
            'event_id' => 'mock-evt-abc123',
            'event_type' => 'payment.captured',
            'payment_reference' => 'mock-'.$this->paymentUuid,
            'status' => 'CAPTURED',
            'provider' => 'mock',
            'timestamp' => '2026-04-16T10:00:00Z',
        ];

        $event = $this->adapter->parseWebhook($payload, []);

        $this->assertSame('mock-evt-abc123', $event->providerEventId);
        $this->assertSame('mock-'.$this->paymentUuid, $event->providerReference);
        $this->assertSame('payment.captured', $event->eventType);
        $this->assertSame('captured', $event->normalizedStatus);
        $this->assertSame('CAPTURED', $event->rawStatus);
        $this->assertSame($payload, $event->rawPayload);
    }

    public function test_parse_webhook_throws_when_event_id_missing(): void
    {
        $this->expectException(ProviderHardFailureException::class);

        $this->adapter->parseWebhook(['payment_reference' => 'mock-ref', 'status' => 'CAPTURED'], []);
    }

    public function test_parse_webhook_throws_when_payment_reference_missing(): void
    {
        $this->expectException(ProviderHardFailureException::class);

        $this->adapter->parseWebhook(['event_id' => 'evt-1', 'status' => 'CAPTURED'], []);
    }

    // ── mapStatus ─────────────────────────────────────────────────────────────

    public function test_map_status_authorized(): void
    {
        $this->assertSame('authorized', $this->adapter->mapStatus('AUTHORIZED'));
    }

    public function test_map_status_captured(): void
    {
        $this->assertSame('captured', $this->adapter->mapStatus('CAPTURED'));
    }

    public function test_map_status_failed(): void
    {
        $this->assertSame('failed', $this->adapter->mapStatus('FAILED'));
    }

    public function test_map_status_refunded(): void
    {
        $this->assertSame('refunded', $this->adapter->mapStatus('REFUNDED'));
    }

    public function test_map_status_pending(): void
    {
        $this->assertSame('pending', $this->adapter->mapStatus('PENDING'));
    }

    public function test_map_status_is_case_insensitive(): void
    {
        $this->assertSame('captured', $this->adapter->mapStatus('captured'));
    }

    public function test_map_status_unknown_throws_hard_failure_exception(): void
    {
        $this->expectException(ProviderHardFailureException::class);

        $this->adapter->mapStatus('TOTALLY_UNKNOWN');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeRequest(): AuthorizeRequest
    {
        return new AuthorizeRequest(
            paymentUuid: $this->paymentUuid,
            correlationId: $this->correlationId,
        );
    }

    private function captureRequest(): CaptureRequest
    {
        return new CaptureRequest(
            paymentUuid: $this->paymentUuid,
            providerReference: 'mock-ref-001',
            correlationId: $this->correlationId,
        );
    }

    private function refundRequest(): RefundRequest
    {
        return new RefundRequest(
            refundUuid: $this->refundUuid,
            paymentUuid: $this->paymentUuid,
            providerReference: 'mock-'.$this->paymentUuid,
            correlationId: $this->correlationId,
        );
    }
}
