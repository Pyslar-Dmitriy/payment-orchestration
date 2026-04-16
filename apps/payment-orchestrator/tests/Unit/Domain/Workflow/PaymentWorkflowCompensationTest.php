<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Workflow;

use App\Domain\Activity\LedgerPostActivity;
use App\Domain\Activity\MerchantCallbackActivity;
use App\Domain\Activity\PublishDomainEventActivity;
use App\Domain\Activity\UpdatePaymentStatusActivity;
use App\Domain\DTO\PaymentWorkflowInput;
use App\Domain\Workflow\PaymentWorkflowImpl;
use Generator;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use ReflectionProperty;
use Temporal\Exception\Failure\ActivityFailure;
use Tests\TestCase;

class PaymentWorkflowCompensationTest extends TestCase
{
    private PaymentWorkflowInput $input;

    protected function setUp(): void
    {
        parent::setUp();

        $this->input = new PaymentWorkflowInput(
            paymentUuid: 'pay-uuid-123',
            merchantId: 'merch-uuid-1',
            amount: 10000,
            currency: 'USD',
            country: 'US',
            correlationId: 'corr-uuid-123',
        );
    }

    // ── handleClassBFailure — activity calls ─────────────────────────────────

    public function test_handle_class_b_failure_marks_requires_reconciliation_with_correct_args(): void
    {
        $workflow = new PaymentWorkflowImpl;

        $updateMock = $this->createMock(UpdatePaymentStatusActivity::class);
        $updateMock->expects($this->once())
            ->method('markRequiresReconciliation')
            ->with('pay-uuid-123', 'corr-uuid-123', 'ledger_post');

        $publishMock = $this->createMock(PublishDomainEventActivity::class);

        $this->setProperty($workflow, 'updateStatusActivity', $updateMock);
        $this->setProperty($workflow, 'publishActivity', $publishMock);

        $this->driveGenerator($this->callHandleClassBFailure(
            $workflow, $this->input, 'ledger_post', 'captured', 'Ledger error',
        ));
    }

    public function test_handle_class_b_failure_publishes_event_with_correct_args(): void
    {
        $workflow = new PaymentWorkflowImpl;

        $updateMock = $this->createMock(UpdatePaymentStatusActivity::class);
        $publishMock = $this->createMock(PublishDomainEventActivity::class);
        $publishMock->expects($this->once())
            ->method('publishPaymentRequiresReconciliation')
            ->with('pay-uuid-123', 'corr-uuid-123', 'ledger_post', 'captured', 'Ledger permanently failed');

        $this->setProperty($workflow, 'updateStatusActivity', $updateMock);
        $this->setProperty($workflow, 'publishActivity', $publishMock);

        $this->driveGenerator($this->callHandleClassBFailure(
            $workflow, $this->input, 'ledger_post', 'captured', 'Ledger permanently failed',
        ));
    }

    public function test_handle_class_b_failure_does_not_call_failed_transition(): void
    {
        $workflow = new PaymentWorkflowImpl;

        $updateMock = $this->createMock(UpdatePaymentStatusActivity::class);
        $updateMock->expects($this->never())->method('markFailed');
        $updateMock->expects($this->once())->method('markRequiresReconciliation');

        $this->setProperty($workflow, 'updateStatusActivity', $updateMock);
        $this->setProperty($workflow, 'publishActivity', $this->createMock(PublishDomainEventActivity::class));

        $this->driveGenerator($this->callHandleClassBFailure(
            $workflow, $this->input, 'ledger_post', 'captured', 'error',
        ));
    }

    // ── handleClassBFailure — error alert log ────────────────────────────────

    public function test_handle_class_b_failure_emits_error_alert_log(): void
    {
        Log::spy();

        $workflow = new PaymentWorkflowImpl;
        $this->setProperty($workflow, 'updateStatusActivity', $this->createMock(UpdatePaymentStatusActivity::class));
        $this->setProperty($workflow, 'publishActivity', $this->createMock(PublishDomainEventActivity::class));

        $this->driveGenerator($this->callHandleClassBFailure(
            $workflow, $this->input, 'ledger_post', 'captured', 'Disk full',
        ));

        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'Payment requires reconciliation — manual intervention needed',
                \Mockery::on(fn ($ctx) => $ctx['alert'] === true
                    && $ctx['payment_id'] === 'pay-uuid-123'
                    && $ctx['correlation_id'] === 'corr-uuid-123'
                    && $ctx['failed_step'] === 'ledger_post'
                ),
            );
    }

    public function test_alert_log_includes_the_specific_failed_step(): void
    {
        Log::spy();

        $workflow = new PaymentWorkflowImpl;
        $this->setProperty($workflow, 'updateStatusActivity', $this->createMock(UpdatePaymentStatusActivity::class));
        $this->setProperty($workflow, 'publishActivity', $this->createMock(PublishDomainEventActivity::class));

        $this->driveGenerator($this->callHandleClassBFailure(
            $workflow, $this->input, 'provider_status_query', 'authorized', 'Timeout',
        ));

        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'Payment requires reconciliation — manual intervention needed',
                \Mockery::on(fn ($ctx) => $ctx['failed_step'] === 'provider_status_query'),
            );
    }

    // ── proceedToCaptureAndLedger — Class B compensation path ────────────────

    public function test_triggers_class_b_when_ledger_permanently_fails_after_confirmed_capture(): void
    {
        $workflow = new PaymentWorkflowImpl;

        $updateMock = $this->createMock(UpdatePaymentStatusActivity::class);
        $updateMock->expects($this->once())->method('markCaptured')->with('pay-uuid-123', 'corr-uuid-123');
        $updateMock->expects($this->once())->method('markRequiresReconciliation')
            ->with('pay-uuid-123', 'corr-uuid-123', 'ledger_post');
        $updateMock->expects($this->never())->method('markFailed');

        $ledgerMock = $this->createMock(LedgerPostActivity::class);
        $ledgerMock->method('postCapture')->willThrowException(
            new ActivityFailure(0, 0, 'LedgerPost', 'ledger-act-1', 0, 'test-worker',
                new \RuntimeException('disk quota exceeded'),
            ),
        );

        $publishMock = $this->createMock(PublishDomainEventActivity::class);
        $publishMock->expects($this->once())->method('publishPaymentRequiresReconciliation');
        $publishMock->expects($this->never())->method('publishPaymentCaptured');

        $callbackMock = $this->createMock(MerchantCallbackActivity::class);
        $callbackMock->expects($this->never())->method('triggerCallback');

        $this->setProperty($workflow, 'updateStatusActivity', $updateMock);
        $this->setProperty($workflow, 'ledgerActivity', $ledgerMock);
        $this->setProperty($workflow, 'publishActivity', $publishMock);
        $this->setProperty($workflow, 'callbackActivity', $callbackMock);

        $method = new ReflectionMethod(PaymentWorkflowImpl::class, 'proceedToCaptureAndLedger');
        $method->setAccessible(true);
        $this->driveGenerator($method->invoke($workflow, $this->input, 'captured'));
    }

    public function test_happy_path_does_not_trigger_class_b_compensation(): void
    {
        $workflow = new PaymentWorkflowImpl;

        $updateMock = $this->createMock(UpdatePaymentStatusActivity::class);
        $updateMock->expects($this->once())->method('markCaptured');
        $updateMock->expects($this->never())->method('markRequiresReconciliation');

        $ledgerMock = $this->createMock(LedgerPostActivity::class);
        // postCapture succeeds (returns null by default)

        $publishMock = $this->createMock(PublishDomainEventActivity::class);
        $publishMock->expects($this->once())->method('publishPaymentCaptured');
        $publishMock->expects($this->never())->method('publishPaymentRequiresReconciliation');

        $callbackMock = $this->createMock(MerchantCallbackActivity::class);
        $callbackMock->expects($this->once())->method('triggerCallback');

        $this->setProperty($workflow, 'updateStatusActivity', $updateMock);
        $this->setProperty($workflow, 'ledgerActivity', $ledgerMock);
        $this->setProperty($workflow, 'publishActivity', $publishMock);
        $this->setProperty($workflow, 'callbackActivity', $callbackMock);

        $method = new ReflectionMethod(PaymentWorkflowImpl::class, 'proceedToCaptureAndLedger');
        $method->setAccessible(true);
        $this->driveGenerator($method->invoke($workflow, $this->input, 'captured'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function callHandleClassBFailure(
        PaymentWorkflowImpl $workflow,
        PaymentWorkflowInput $input,
        string $failedStep,
        string $lastKnownProviderStatus,
        string $failureReason,
    ): Generator {
        $method = new ReflectionMethod(PaymentWorkflowImpl::class, 'handleClassBFailure');
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
