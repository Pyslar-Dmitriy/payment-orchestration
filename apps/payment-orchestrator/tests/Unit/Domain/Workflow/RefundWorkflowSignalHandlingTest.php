<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Workflow;

use App\Domain\Workflow\RefundWorkflowImpl;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class RefundWorkflowSignalHandlingTest extends TestCase
{
    // ── onRefundResult ───────────────────────────────────────────────────────

    public function test_refund_signal_is_buffered(): void
    {
        $workflow = new RefundWorkflowImpl;

        $workflow->onRefundResult([
            'provider_event_id' => 'evt-ref-1',
            'provider_status' => 'refunded',
            'provider_reference' => 'ref-1',
            'correlation_id' => 'corr-1',
        ]);

        $queue = $this->getQueue($workflow);
        $this->assertCount(1, $queue);
        $this->assertSame('evt-ref-1', $queue[0]['provider_event_id']);
        $this->assertSame('refunded', $queue[0]['provider_status']);
    }

    // ── consumeNextSignal — ordering ─────────────────────────────────────────

    public function test_signals_are_consumed_in_arrival_order(): void
    {
        $workflow = new RefundWorkflowImpl;

        $workflow->onRefundResult([
            'provider_event_id' => 'evt-1',
            'provider_status' => 'refunded',
            'provider_reference' => 'ref-1',
            'correlation_id' => 'corr-1',
        ]);

        $workflow->onRefundResult([
            'provider_event_id' => 'evt-2',
            'provider_status' => 'refunded',
            'provider_reference' => 'ref-2',
            'correlation_id' => 'corr-1',
        ]);

        $first = $this->consumeNext($workflow);
        $second = $this->consumeNext($workflow);

        $this->assertSame('evt-1', $first['provider_event_id']);
        $this->assertSame('evt-2', $second['provider_event_id']);
    }

    public function test_consume_returns_null_when_queue_is_empty(): void
    {
        $workflow = new RefundWorkflowImpl;

        $this->assertNull($this->consumeNext($workflow));
    }

    // ── consumeNextSignal — deduplication ────────────────────────────────────

    public function test_duplicate_signal_with_same_event_id_is_skipped(): void
    {
        $workflow = new RefundWorkflowImpl;

        $payload = [
            'provider_event_id' => 'evt-dup',
            'provider_status' => 'refunded',
            'provider_reference' => 'ref-1',
            'correlation_id' => 'corr-1',
        ];

        $workflow->onRefundResult($payload);
        $workflow->onRefundResult($payload); // exact duplicate

        $first = $this->consumeNext($workflow);
        $second = $this->consumeNext($workflow);

        $this->assertNotNull($first);
        $this->assertSame('evt-dup', $first['provider_event_id']);
        $this->assertNull($second); // duplicate was discarded
    }

    public function test_signals_without_event_id_are_never_deduplicated(): void
    {
        $workflow = new RefundWorkflowImpl;

        $workflow->onRefundResult(['provider_status' => 'refunded', 'provider_reference' => 'ref-1', 'correlation_id' => 'corr-1']);
        $workflow->onRefundResult(['provider_status' => 'refunded', 'provider_reference' => 'ref-1', 'correlation_id' => 'corr-1']);

        $first = $this->consumeNext($workflow);
        $second = $this->consumeNext($workflow);

        $this->assertNotNull($first);
        $this->assertNotNull($second); // no dedup without event_id
    }

    public function test_different_event_ids_are_both_processed(): void
    {
        $workflow = new RefundWorkflowImpl;

        $workflow->onRefundResult(['provider_event_id' => 'evt-A', 'provider_status' => 'refunded', 'provider_reference' => 'ref-1', 'correlation_id' => 'corr-1']);
        $workflow->onRefundResult(['provider_event_id' => 'evt-B', 'provider_status' => 'refunded', 'provider_reference' => 'ref-2', 'correlation_id' => 'corr-1']);

        $first = $this->consumeNext($workflow);
        $second = $this->consumeNext($workflow);

        $this->assertSame('evt-A', $first['provider_event_id']);
        $this->assertSame('evt-B', $second['provider_event_id']);
    }

    // ── isRefundSuccessStatus ─────────────────────────────────────────────────

    public function test_refunded_is_success_status(): void
    {
        $this->assertTrue($this->callIsSuccess(new RefundWorkflowImpl, 'refunded'));
    }

    public function test_failed_is_not_success_status(): void
    {
        $this->assertFalse($this->callIsSuccess(new RefundWorkflowImpl, 'failed'));
    }

    public function test_captured_is_not_refund_success_status(): void
    {
        $this->assertFalse($this->callIsSuccess(new RefundWorkflowImpl, 'captured'));
    }

    public function test_empty_string_is_not_success_status(): void
    {
        $this->assertFalse($this->callIsSuccess(new RefundWorkflowImpl, ''));
    }

    public function test_unknown_string_is_not_success_status(): void
    {
        $this->assertFalse($this->callIsSuccess(new RefundWorkflowImpl, 'pending'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function getQueue(RefundWorkflowImpl $workflow): array
    {
        $prop = new ReflectionProperty(RefundWorkflowImpl::class, 'signalQueue');
        $prop->setAccessible(true);

        return $prop->getValue($workflow);
    }

    /** @return array<string, mixed>|null */
    private function consumeNext(RefundWorkflowImpl $workflow): ?array
    {
        $method = new ReflectionMethod(RefundWorkflowImpl::class, 'consumeNextSignal');
        $method->setAccessible(true);

        return $method->invoke($workflow);
    }

    private function callIsSuccess(RefundWorkflowImpl $workflow, string $status): bool
    {
        $method = new ReflectionMethod(RefundWorkflowImpl::class, 'isRefundSuccessStatus');
        $method->setAccessible(true);

        return $method->invoke($workflow, $status);
    }
}
