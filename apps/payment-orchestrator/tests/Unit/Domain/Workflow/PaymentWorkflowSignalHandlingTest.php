<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Workflow;

use App\Domain\Workflow\PaymentWorkflowImpl;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class PaymentWorkflowSignalHandlingTest extends TestCase
{
    // ── onAuthorizationResult ───────────────────────��────────────────────────

    public function test_authorization_signal_is_buffered_with_signal_type_tag(): void
    {
        $workflow = new PaymentWorkflowImpl;

        $workflow->onAuthorizationResult([
            'provider_event_id' => 'evt-auth-1',
            'provider_status' => 'authorized',
            'provider_reference' => 'ref-1',
            'correlation_id' => 'corr-1',
        ]);

        $queue = $this->getQueue($workflow);
        $this->assertCount(1, $queue);
        $this->assertSame('authorization_result', $queue[0]['signal_type']);
        $this->assertSame('evt-auth-1', $queue[0]['provider_event_id']);
    }

    // ── onCaptureResult ──────────────────────────────────────────────────────

    public function test_capture_signal_is_buffered_with_signal_type_tag(): void
    {
        $workflow = new PaymentWorkflowImpl;

        $workflow->onCaptureResult([
            'provider_event_id' => 'evt-cap-1',
            'provider_status' => 'captured',
            'provider_reference' => 'ref-1',
            'correlation_id' => 'corr-1',
        ]);

        $queue = $this->getQueue($workflow);
        $this->assertCount(1, $queue);
        $this->assertSame('capture_result', $queue[0]['signal_type']);
        $this->assertSame('evt-cap-1', $queue[0]['provider_event_id']);
    }

    // ── consumeNextSignal — ordering ─────────────────────────────────────────

    public function test_signals_are_consumed_in_arrival_order(): void
    {
        $workflow = new PaymentWorkflowImpl;

        $workflow->onAuthorizationResult([
            'provider_event_id' => 'evt-1',
            'provider_status' => 'authorized',
            'provider_reference' => 'ref-1',
            'correlation_id' => 'corr-1',
        ]);

        $workflow->onCaptureResult([
            'provider_event_id' => 'evt-2',
            'provider_status' => 'captured',
            'provider_reference' => 'ref-1',
            'correlation_id' => 'corr-1',
        ]);

        $first = $this->consumeNext($workflow);
        $second = $this->consumeNext($workflow);

        $this->assertSame('authorization_result', $first['signal_type']);
        $this->assertSame('capture_result', $second['signal_type']);
    }

    public function test_consume_returns_null_when_queue_is_empty(): void
    {
        $workflow = new PaymentWorkflowImpl;

        $this->assertNull($this->consumeNext($workflow));
    }

    // ── consumeNextSignal — deduplication ────────────────────────────────────

    public function test_duplicate_signal_with_same_event_id_is_skipped(): void
    {
        $workflow = new PaymentWorkflowImpl;

        $payload = [
            'provider_event_id' => 'evt-dup',
            'provider_status' => 'captured',
            'provider_reference' => 'ref-1',
            'correlation_id' => 'corr-1',
        ];

        $workflow->onCaptureResult($payload);
        $workflow->onCaptureResult($payload); // exact duplicate

        $first = $this->consumeNext($workflow);
        $second = $this->consumeNext($workflow);

        $this->assertNotNull($first);
        $this->assertSame('evt-dup', $first['provider_event_id']);
        $this->assertNull($second); // duplicate was discarded
    }

    public function test_signals_without_event_id_are_never_deduplicated(): void
    {
        $workflow = new PaymentWorkflowImpl;

        $workflow->onCaptureResult(['provider_status' => 'captured', 'provider_reference' => 'ref-1', 'correlation_id' => 'corr-1']);
        $workflow->onCaptureResult(['provider_status' => 'captured', 'provider_reference' => 'ref-1', 'correlation_id' => 'corr-1']);

        $first = $this->consumeNext($workflow);
        $second = $this->consumeNext($workflow);

        $this->assertNotNull($first);
        $this->assertNotNull($second); // no dedup without event_id
    }

    public function test_different_event_ids_are_both_processed(): void
    {
        $workflow = new PaymentWorkflowImpl;

        $workflow->onCaptureResult(['provider_event_id' => 'evt-A', 'provider_status' => 'captured', 'provider_reference' => 'ref-1', 'correlation_id' => 'corr-1']);
        $workflow->onCaptureResult(['provider_event_id' => 'evt-B', 'provider_status' => 'captured', 'provider_reference' => 'ref-1', 'correlation_id' => 'corr-1']);

        $first = $this->consumeNext($workflow);
        $second = $this->consumeNext($workflow);

        $this->assertSame('evt-A', $first['provider_event_id']);
        $this->assertSame('evt-B', $second['provider_event_id']);
    }

    // ── isProviderSuccessStatus ──────────────────────────────────────────────

    public function test_authorized_is_success_status(): void
    {
        $this->assertTrue($this->callIsSuccess(new PaymentWorkflowImpl, 'authorized'));
    }

    public function test_captured_is_success_status(): void
    {
        $this->assertTrue($this->callIsSuccess(new PaymentWorkflowImpl, 'captured'));
    }

    public function test_failed_is_not_success_status(): void
    {
        $this->assertFalse($this->callIsSuccess(new PaymentWorkflowImpl, 'failed'));
    }

    public function test_cancelled_is_not_success_status(): void
    {
        $this->assertFalse($this->callIsSuccess(new PaymentWorkflowImpl, 'cancelled'));
    }

    public function test_empty_string_is_not_success_status(): void
    {
        $this->assertFalse($this->callIsSuccess(new PaymentWorkflowImpl, ''));
    }

    public function test_unknown_string_is_not_success_status(): void
    {
        $this->assertFalse($this->callIsSuccess(new PaymentWorkflowImpl, 'pending'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function getQueue(PaymentWorkflowImpl $workflow): array
    {
        $prop = new ReflectionProperty(PaymentWorkflowImpl::class, 'signalQueue');
        $prop->setAccessible(true);

        return $prop->getValue($workflow);
    }

    /** @return array<string, mixed>|null */
    private function consumeNext(PaymentWorkflowImpl $workflow): ?array
    {
        $method = new ReflectionMethod(PaymentWorkflowImpl::class, 'consumeNextSignal');
        $method->setAccessible(true);

        return $method->invoke($workflow);
    }

    private function callIsSuccess(PaymentWorkflowImpl $workflow, string $status): bool
    {
        $method = new ReflectionMethod(PaymentWorkflowImpl::class, 'isProviderSuccessStatus');
        $method->setAccessible(true);

        return $method->invoke($workflow, $status);
    }
}
