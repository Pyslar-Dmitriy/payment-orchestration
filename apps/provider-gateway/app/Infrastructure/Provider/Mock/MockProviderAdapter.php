<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Mock;

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
use App\Infrastructure\Provider\Mock\Jobs\DeliverMockWebhookJob;

/**
 * Controllable mock PSP adapter for integration and load tests.
 *
 * Scenario is read from config('mock_provider.scenario') on each call so
 * that tests can switch scenarios without rebuilding the adapter:
 *
 *   config()->set('mock_provider.scenario', MockScenario::Timeout->value);
 *
 * Webhook delivery (async scenarios) dispatches DeliverMockWebhookJob to the
 * queue. In tests the queue is synchronous, so set Queue::fake() to capture
 * dispatched jobs without executing them, or ensure MOCK_PROVIDER_WEBHOOK_URL
 * is not set (the job is then a no-op).
 */
final class MockProviderAdapter implements ProviderAdapterInterface
{
    public function providerKey(): string
    {
        return 'mock';
    }

    public function authorize(AuthorizeRequest $request): AuthorizeResponse
    {
        $ref = $this->paymentRef($request->paymentUuid);

        return match ($this->scenario()) {
            MockScenario::Success => new AuthorizeResponse($ref, 'captured', false, true),
            MockScenario::Timeout => throw new ProviderTransientException('Mock provider simulated timeout'),
            MockScenario::HardFailure => throw new ProviderHardFailureException('Mock provider declined', 'mock_declined'),
            MockScenario::AsyncWebhook => $this->dispatchAndReturn($ref, $request->paymentUuid, [
                $this->event($request->paymentUuid, 'payment.captured', 'CAPTURED', 0),
            ]),
            MockScenario::DelayedWebhook => $this->dispatchAndReturn($ref, $request->paymentUuid, [
                $this->event($request->paymentUuid, 'payment.captured', 'CAPTURED', (int) config('mock_provider.webhook_delay_seconds', 5)),
            ]),
            MockScenario::DuplicateWebhook => $this->dispatchAndReturn($ref, $request->paymentUuid, [
                $this->event($request->paymentUuid, 'payment.captured', 'CAPTURED', 0, 'dup'),
                $this->event($request->paymentUuid, 'payment.captured', 'CAPTURED', 0, 'dup'),
            ]),
            MockScenario::OutOfOrder => $this->dispatchAndReturn($ref, $request->paymentUuid, [
                // Capture arrives first — authorize arrives second (wrong order).
                $this->event($request->paymentUuid, 'payment.captured', 'CAPTURED', 0),
                $this->event($request->paymentUuid, 'payment.authorized', 'AUTHORIZED', 1),
            ]),
        };
    }

    public function capture(CaptureRequest $request): CaptureResponse
    {
        return match ($this->scenario()) {
            MockScenario::Timeout => throw new ProviderTransientException('Mock provider simulated timeout'),
            MockScenario::HardFailure => throw new ProviderHardFailureException('Mock provider declined', 'mock_declined'),
            default => new CaptureResponse($request->providerReference, 'captured', false),
        };
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        $ref = 'mock-refund-'.$request->refundUuid;

        return match ($this->scenario()) {
            MockScenario::Timeout => throw new ProviderTransientException('Mock provider simulated timeout'),
            MockScenario::HardFailure => throw new ProviderHardFailureException('Mock provider declined refund', 'mock_refund_declined'),
            default => new RefundResponse($ref, 'refunded', false),
        };
    }

    public function queryPaymentStatus(StatusQueryRequest $request): StatusQueryResponse
    {
        return match ($this->scenario()) {
            MockScenario::Timeout => throw new ProviderTransientException('Mock provider simulated timeout'),
            MockScenario::HardFailure => throw new ProviderHardFailureException('Mock provider declined', 'mock_declined'),
            default => new StatusQueryResponse('captured', true, false, false),
        };
    }

    public function queryRefundStatus(RefundStatusQueryRequest $request): RefundStatusQueryResponse
    {
        return match ($this->scenario()) {
            MockScenario::Timeout => throw new ProviderTransientException('Mock provider simulated timeout'),
            MockScenario::HardFailure => throw new ProviderHardFailureException('Mock provider declined', 'mock_declined'),
            default => new RefundStatusQueryResponse('refunded', true, false),
        };
    }

    public function parseWebhook(array $payload, array $headers): ParsedWebhookEvent
    {
        $eventId = $payload['event_id'] ?? throw new ProviderHardFailureException('Missing event_id in mock webhook payload', 'mock_parse_error');
        $paymentRef = $payload['payment_reference'] ?? throw new ProviderHardFailureException('Missing payment_reference in mock webhook payload', 'mock_parse_error');
        $eventType = $payload['event_type'] ?? 'payment.unknown';
        $rawStatus = $payload['status'] ?? 'UNKNOWN';

        return new ParsedWebhookEvent(
            providerEventId: $eventId,
            providerReference: $paymentRef,
            eventType: $eventType,
            normalizedStatus: $this->mapStatus($rawStatus),
            rawStatus: $rawStatus,
            rawPayload: $payload,
        );
    }

    public function mapStatus(string $rawStatus): string
    {
        return match (strtoupper($rawStatus)) {
            'AUTHORIZED' => 'authorized',
            'CAPTURED' => 'captured',
            'FAILED' => 'failed',
            'REFUNDED' => 'refunded',
            'PENDING' => 'pending',
            default => throw new ProviderHardFailureException("Unknown mock provider status: {$rawStatus}", 'mock_unknown_status'),
        };
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function scenario(): MockScenario
    {
        return MockScenario::from(config('mock_provider.scenario', MockScenario::Success->value));
    }

    private function paymentRef(string $paymentUuid): string
    {
        return 'mock-'.$paymentUuid;
    }

    /**
     * @param  array<array{event_id: string, event_type: string, status: string, delay: int}>  $events
     */
    private function dispatchAndReturn(string $ref, string $paymentUuid, array $events): AuthorizeResponse
    {
        $webhookUrl = config('mock_provider.webhook_url');

        foreach ($events as $event) {
            $job = new DeliverMockWebhookJob(
                eventId: $event['event_id'],
                paymentReference: $ref,
                eventType: $event['event_type'],
                status: $event['status'],
                webhookUrl: $webhookUrl,
            );

            if ($event['delay'] > 0) {
                dispatch($job)->delay(now()->addSeconds($event['delay']));
            } else {
                dispatch($job);
            }
        }

        return new AuthorizeResponse($ref, 'pending', true, false);
    }

    /**
     * @return array{event_id: string, event_type: string, status: string, delay: int}
     */
    private function event(string $paymentUuid, string $eventType, string $status, int $delay, string $suffix = ''): array
    {
        $base = 'mock-evt-'.$paymentUuid.'-'.strtolower(str_replace('.', '-', $eventType));
        $eventId = $suffix !== '' ? $base.'-'.$suffix : $base;

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'status' => $status,
            'delay' => $delay,
        ];
    }
}
