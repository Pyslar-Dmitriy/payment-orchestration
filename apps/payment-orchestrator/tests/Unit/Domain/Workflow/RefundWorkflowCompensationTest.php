<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Workflow;

use App\Domain\Activity\LedgerPostActivity;
use App\Domain\Activity\MerchantCallbackActivity;
use App\Domain\Activity\PublishDomainEventActivity;
use App\Domain\Activity\UpdateRefundStatusActivity;
use App\Domain\DTO\RefundWorkflowInput;
use App\Domain\Workflow\RefundWorkflowImpl;
use Generator;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use ReflectionProperty;
use Temporal\Exception\Failure\ActivityFailure;
use Tests\TestCase;

class RefundWorkflowCompensationTest extends TestCase
{
    private RefundWorkflowInput $input;

    protected function setUp(): void
    {
        parent::setUp();

        $this->input = new RefundWorkflowInput(
            refundUuid: 'ref-uuid-456',
            paymentUuid: 'pay-uuid-123',
            merchantId: 'merch-uuid-1',
            amount: 5000,
            currency: 'USD',
            providerKey: 'mock-provider',
            correlationId: 'corr-uuid-456',
        );
    }

    // ── handleClassBFailure — activity calls ─────────────────────────────────

    public function test_handle_class_b_failure_marks_requires_reconciliation_with_correct_args(): void
    {
        $workflow = new RefundWorkflowImpl;

        $updateMock = $this->createMock(UpdateRefundStatusActivity::class);
        $updateMock->expects($this->once())
            ->method('markRequiresReconciliation')
            ->with('ref-uuid-456', 'corr-uuid-456', 'ledger_post_refund');

        $publishMock = $this->createMock(PublishDomainEventActivity::class);

        $this->setProperty($workflow, 'updateStatusActivity', $updateMock);
        $this->setProperty($workflow, 'publishActivity', $publishMock);

        $this->driveGenerator($this->callHandleClassBFailure(
            $workflow, $this->input, 'ledger_post_refund', 'refunded', 'Ledger timeout',
        ));
    }

    public function test_handle_class_b_failure_publishes_event_with_correct_args(): void
    {
        $workflow = new RefundWorkflowImpl;

        $updateMock = $this->createMock(UpdateRefundStatusActivity::class);
        $publishMock = $this->createMock(PublishDomainEventActivity::class);
        $publishMock->expects($this->once())
            ->method('publishRefundRequiresReconciliation')
            ->with('ref-uuid-456', 'corr-uuid-456', 'ledger_post_refund', 'refunded', 'DB full');

        $this->setProperty($workflow, 'updateStatusActivity', $updateMock);
        $this->setProperty($workflow, 'publishActivity', $publishMock);

        $this->driveGenerator($this->callHandleClassBFailure(
            $workflow, $this->input, 'ledger_post_refund', 'refunded', 'DB full',
        ));
    }

    public function test_handle_class_b_failure_does_not_call_failed_transition(): void
    {
        $workflow = new RefundWorkflowImpl;

        $updateMock = $this->createMock(UpdateRefundStatusActivity::class);
        $updateMock->expects($this->never())->method('markFailed');
        $updateMock->expects($this->once())->method('markRequiresReconciliation');

        $this->setProperty($workflow, 'updateStatusActivity', $updateMock);
        $this->setProperty($workflow, 'publishActivity', $this->createMock(PublishDomainEventActivity::class));

        $this->driveGenerator($this->callHandleClassBFailure(
            $workflow, $this->input, 'ledger_post_refund', 'refunded', 'error',
        ));
    }

    // ── handleClassBFailure — error alert log ────────────────────────────────

    public function test_handle_class_b_failure_emits_error_alert_log(): void
    {
        Log::spy();

        $workflow = new RefundWorkflowImpl;
        $this->setProperty($workflow, 'updateStatusActivity', $this->createMock(UpdateRefundStatusActivity::class));
        $this->setProperty($workflow, 'publishActivity', $this->createMock(PublishDomainEventActivity::class));

        $this->driveGenerator($this->callHandleClassBFailure(
            $workflow, $this->input, 'ledger_post_refund', 'refunded', 'Disk full',
        ));

        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'Refund requires reconciliation — manual intervention needed',
                \Mockery::on(fn ($ctx) => $ctx['alert'] === true
                    && $ctx['refund_id'] === 'ref-uuid-456'
                    && $ctx['correlation_id'] === 'corr-uuid-456'
                    && $ctx['failed_step'] === 'ledger_post_refund'
                ),
            );
    }

    public function test_alert_log_includes_the_specific_failed_step(): void
    {
        Log::spy();

        $workflow = new RefundWorkflowImpl;
        $this->setProperty($workflow, 'updateStatusActivity', $this->createMock(UpdateRefundStatusActivity::class));
        $this->setProperty($workflow, 'publishActivity', $this->createMock(PublishDomainEventActivity::class));

        $this->driveGenerator($this->callHandleClassBFailure(
            $workflow, $this->input, 'provider_status_query', 'unknown', 'Timeout',
        ));

        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'Refund requires reconciliation — manual intervention needed',
                \Mockery::on(fn ($ctx) => $ctx['failed_step'] === 'provider_status_query'),
            );
    }

    // ── proceedToLedgerAndCallback — Class B compensation path ───────────────

    public function test_triggers_class_b_when_ledger_permanently_fails_after_confirmed_refund(): void
    {
        $workflow = new RefundWorkflowImpl;

        $updateMock = $this->createMock(UpdateRefundStatusActivity::class);
        $updateMock->expects($this->once())->method('markCompleted')->with('ref-uuid-456', 'corr-uuid-456');
        $updateMock->expects($this->once())->method('markRequiresReconciliation')
            ->with('ref-uuid-456', 'corr-uuid-456', 'ledger_post_refund');
        $updateMock->expects($this->never())->method('markFailed');

        $ledgerMock = $this->createMock(LedgerPostActivity::class);
        $ledgerMock->method('postRefund')->willThrowException(
            new ActivityFailure(0, 0, 'LedgerPost', 'ledger-act-1', 0, 'test-worker',
                new \RuntimeException('connection refused'),
            ),
        );

        $publishMock = $this->createMock(PublishDomainEventActivity::class);
        $publishMock->expects($this->once())->method('publishRefundRequiresReconciliation');
        $publishMock->expects($this->never())->method('publishRefundCompleted');

        $callbackMock = $this->createMock(MerchantCallbackActivity::class);
        $callbackMock->expects($this->never())->method('triggerCallback');

        $this->setProperty($workflow, 'updateStatusActivity', $updateMock);
        $this->setProperty($workflow, 'ledgerActivity', $ledgerMock);
        $this->setProperty($workflow, 'publishActivity', $publishMock);
        $this->setProperty($workflow, 'callbackActivity', $callbackMock);

        $method = new ReflectionMethod(RefundWorkflowImpl::class, 'proceedToLedgerAndCallback');
        $method->setAccessible(true);
        $this->driveGenerator($method->invoke($workflow, $this->input, 'refunded'));
    }

    public function test_happy_path_does_not_trigger_class_b_compensation(): void
    {
        $workflow = new RefundWorkflowImpl;

        $updateMock = $this->createMock(UpdateRefundStatusActivity::class);
        $updateMock->expects($this->once())->method('markCompleted');
        $updateMock->expects($this->never())->method('markRequiresReconciliation');

        $ledgerMock = $this->createMock(LedgerPostActivity::class);
        // postRefund succeeds (returns null by default)

        $publishMock = $this->createMock(PublishDomainEventActivity::class);
        $publishMock->expects($this->once())->method('publishRefundCompleted');
        $publishMock->expects($this->never())->method('publishRefundRequiresReconciliation');

        $callbackMock = $this->createMock(MerchantCallbackActivity::class);
        $callbackMock->expects($this->once())->method('triggerCallback');

        $this->setProperty($workflow, 'updateStatusActivity', $updateMock);
        $this->setProperty($workflow, 'ledgerActivity', $ledgerMock);
        $this->setProperty($workflow, 'publishActivity', $publishMock);
        $this->setProperty($workflow, 'callbackActivity', $callbackMock);

        $method = new ReflectionMethod(RefundWorkflowImpl::class, 'proceedToLedgerAndCallback');
        $method->setAccessible(true);
        $this->driveGenerator($method->invoke($workflow, $this->input, 'refunded'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function callHandleClassBFailure(
        RefundWorkflowImpl $workflow,
        RefundWorkflowInput $input,
        string $failedStep,
        string $lastKnownProviderStatus,
        string $failureReason,
    ): Generator {
        $method = new ReflectionMethod(RefundWorkflowImpl::class, 'handleClassBFailure');
        $method->setAccessible(true);

        return $method->invoke($workflow, $input, $failedStep, $lastKnownProviderStatus, $failureReason);
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        $prop = new ReflectionProperty($object::class, $property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    private function driveGenerator(Generator $gen): void
    {
        foreach ($gen as $_) {
        }
    }
}
