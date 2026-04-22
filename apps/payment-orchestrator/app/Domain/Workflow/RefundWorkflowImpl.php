<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use App\Domain\Activity\LedgerPostActivity;
use App\Domain\Activity\MerchantCallbackActivity;
use App\Domain\Activity\ProviderCallActivity;
use App\Domain\Activity\ProviderStatusQueryActivity;
use App\Domain\Activity\PublishDomainEventActivity;
use App\Domain\Activity\UpdateRefundStatusActivity;
use App\Domain\DTO\ProviderCallResult;
use App\Domain\DTO\RefundStatusResult;
use App\Domain\DTO\RefundWorkflowInput;
use Generator;
use Illuminate\Support\Facades\Log;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Workflow;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\WorkflowMethod;

class RefundWorkflowImpl implements RefundWorkflow
{
    private const WEBHOOK_TIMEOUT_SECONDS = 1800; // 30 minutes

    /** @var list<array<string, mixed>> Incoming signals buffered in arrival order. */
    private array $signalQueue = [];

    /** @var list<string> Event IDs that have already been processed (deduplication). */
    private array $processedEventIds = [];

    // Activity stubs — initialised once per workflow execution in initActivities().
    private ProviderCallActivity $providerCallActivity;

    private ProviderStatusQueryActivity $providerQueryActivity;

    private UpdateRefundStatusActivity $updateStatusActivity;

    private LedgerPostActivity $ledgerActivity;

    private MerchantCallbackActivity $callbackActivity;

    private PublishDomainEventActivity $publishActivity;

    // ── Signal handlers ────────────────────────────────────────────────────────

    #[SignalMethod(name: 'provider.refund_result')]
    public function onRefundResult(array $payload): void
    {
        $this->signalQueue[] = $payload;
    }

    // ── Main workflow ──────────────────────────────────────────────────────────

    #[WorkflowMethod(name: 'RefundWorkflow')]
    public function run(RefundWorkflowInput $input): Generator
    {
        $this->initActivities();

        // Step 1: Transition refund to pending_provider.
        yield $this->updateStatusActivity->markPendingProvider($input->refundUuid, $input->correlationId);

        // Step 2: Submit the refund request to the provider.
        try {
            /** @var ProviderCallResult $callResult */
            $callResult = yield $this->providerCallActivity->refund(
                $input->refundUuid, $input->paymentUuid, $input->providerKey, $input->correlationId,
            );
        } catch (ActivityFailure $e) {
            yield from $this->handleClassAFailure($input, 'provider_hard_failure');

            return;
        }

        // Step 3: Synchronous provider — result is already known, no webhook wait needed.
        if (! $callResult->isAsync) {
            yield from $this->handleSyncProviderResult($callResult, $input);

            return;
        }

        // Steps 4–6: Wait for async webhook signal, recover from timeout, evaluate result.
        $refundStatus = yield from $this->awaitRefundConfirmation($input);

        if ($refundStatus === null) {
            return;
        }

        // Step 7: Happy path — mark completed, post to ledger, publish event, trigger callback.
        yield from $this->proceedToLedgerAndCallback($input, $refundStatus);
    }

    // ── Private workflow steps ─────────────────────────────────────────────────

    /**
     * Handles the result when the provider answered synchronously (no webhook wait needed).
     */
    private function handleSyncProviderResult(ProviderCallResult $callResult, RefundWorkflowInput $input): Generator
    {
        if (! $this->isRefundSuccessStatus($callResult->providerStatus)) {
            yield from $this->handleClassAFailure($input, $callResult->providerStatus);

            return;
        }

        yield from $this->proceedToLedgerAndCallback($input, $callResult->providerStatus);
    }

    /**
     * Waits for the provider.refund_result webhook signal and handles timeout recovery.
     *
     * Returns the provider status string on success.
     * Returns null after handling failure internally when the refund cannot be confirmed.
     *
     * @return Generator<mixed, mixed, mixed, string|null>
     */
    private function awaitRefundConfirmation(RefundWorkflowInput $input): Generator
    {
        $startTime = Workflow::now();
        $refundStatus = null;

        while (true) {
            $elapsed = Workflow::now()->getTimestamp() - $startTime->getTimestamp();
            $remaining = self::WEBHOOK_TIMEOUT_SECONDS - $elapsed;

            if ($remaining <= 0) {
                break;
            }

            $received = yield Workflow::awaitWithTimeout(
                (int) $remaining,
                fn () => ! empty($this->signalQueue),
            );

            if (! $received) {
                break;
            }

            $signal = $this->consumeNextSignal();

            if ($signal === null) {
                continue;
            }

            $refundStatus = $signal['provider_status'] ?? '';
            break;
        }

        // Signal loop exited without a result — query the provider before deciding.
        if ($refundStatus === null) {
            $refundStatus = yield from $this->resolveAfterTimeout($input);

            if ($refundStatus === null) {
                return null;
            }
        }

        if (! $this->isRefundSuccessStatus($refundStatus)) {
            yield from $this->handleClassAFailure($input, $refundStatus);

            return null;
        }

        return $refundStatus;
    }

    /**
     * Queries the provider after a signal timeout to recover the refund outcome.
     *
     * Returns the provider status string when the refund is confirmed.
     * Returns null after handling failure internally when the outcome is negative or ambiguous.
     *
     * @return Generator<mixed, mixed, mixed, string|null>
     */
    private function resolveAfterTimeout(RefundWorkflowInput $input): Generator
    {
        try {
            /** @var RefundStatusResult $queryResult */
            $queryResult = yield $this->providerQueryActivity->queryRefundStatus(
                $input->refundUuid, $input->providerKey, $input->correlationId,
            );
        } catch (ActivityFailure $e) {
            // Provider query failed — apply Class B compensation since the provider may have
            // already processed the refund, meaning funds may have been returned to the customer.
            yield from $this->handleClassBFailure(
                $input, 'provider_status_query', 'unknown', $e->getMessage(),
            );

            return null;
        }

        if ($queryResult->isRefunded) {
            return $queryResult->providerStatus;
        }

        if ($queryResult->isFailed) {
            yield from $this->handleClassAFailure($input, $queryResult->providerStatus);

            return null;
        }

        // Ambiguous / unknown status — apply Class B as the provider may have issued the refund.
        yield from $this->handleClassBFailure(
            $input, 'provider_status_query', 'unknown_provider_status', 'Ambiguous status after timeout',
        );

        return null;
    }

    /**
     * Happy-path completion: mark completed → post ledger reversal → publish → callback.
     * On permanent ledger failure, applies ADR-010 Class B compensation instead.
     */
    private function proceedToLedgerAndCallback(RefundWorkflowInput $input, string $refundStatus): Generator
    {
        yield $this->updateStatusActivity->markCompleted($input->refundUuid, $input->correlationId);

        try {
            yield $this->ledgerActivity->postRefund($input->refundUuid, $input->correlationId);
        } catch (ActivityFailure $e) {
            // Ledger failed permanently after confirmed provider refund — ADR-010 Class B.
            yield from $this->handleClassBFailure($input, 'ledger_post_refund', $refundStatus, $e->getMessage());

            return;
        }

        yield $this->publishActivity->publishRefundCompleted($input->refundUuid, $input->correlationId);
        yield $this->callbackActivity->triggerCallback(
            $input->paymentUuid, $input->merchantId, $input->amount, $input->currency,
            'refund.completed', $input->correlationId, $input->refundUuid,
        );
    }

    /**
     * ADR-010 Class A: no external side effect confirmed — mark failed, publish, callback.
     */
    private function handleClassAFailure(RefundWorkflowInput $input, string $reason): Generator
    {
        yield $this->updateStatusActivity->markFailed($input->refundUuid, $input->correlationId, $reason);
        yield $this->publishActivity->publishRefundFailed($input->refundUuid, $input->correlationId);
        yield $this->callbackActivity->triggerCallback(
            $input->paymentUuid, $input->merchantId, $input->amount, $input->currency,
            'refund.failed', $input->correlationId, $input->refundUuid,
        );
    }

    /**
     * ADR-010 Class B: provider may have already issued the refund — mark requires_reconciliation,
     * publish operator alert event. No merchant callback — manual intervention required.
     */
    private function handleClassBFailure(
        RefundWorkflowInput $input,
        string $failedStep,
        string $lastKnownProviderStatus,
        string $failureReason,
    ): Generator {
        Log::error('Refund requires reconciliation — manual intervention needed', [
            'alert' => true,
            'refund_id' => $input->refundUuid,
            'correlation_id' => $input->correlationId,
            'failed_step' => $failedStep,
        ]);

        yield $this->updateStatusActivity->markRequiresReconciliation(
            $input->refundUuid, $input->correlationId, $failedStep,
        );
        yield $this->publishActivity->publishRefundRequiresReconciliation(
            $input->refundUuid,
            $input->correlationId,
            $failedStep,
            $lastKnownProviderStatus,
            $failureReason,
        );
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Initialises Temporal activity stubs with appropriate timeout and retry settings.
     * Called once at the start of each workflow execution.
     */
    private function initActivities(): void
    {
        $defaultOptions = ActivityOptions::new()
            ->withStartToCloseTimeout(30)
            ->withRetryOptions(
                RetryOptions::new()
                    ->withMaximumAttempts(3)
                    ->withInitialInterval(2)
                    ->withBackoffCoefficient(2.0)
                    ->withMaximumInterval(30),
            );

        $providerCallOptions = ActivityOptions::new()
            ->withStartToCloseTimeout(60)
            ->withRetryOptions(
                RetryOptions::new()
                    ->withMaximumAttempts(3)
                    ->withInitialInterval(5)
                    ->withBackoffCoefficient(2.0)
                    ->withMaximumInterval(60),
            );

        $this->providerCallActivity = Workflow::newActivityStub(ProviderCallActivity::class, $providerCallOptions);
        $this->providerQueryActivity = Workflow::newActivityStub(ProviderStatusQueryActivity::class, $defaultOptions);
        $this->updateStatusActivity = Workflow::newActivityStub(UpdateRefundStatusActivity::class, $defaultOptions);
        $this->ledgerActivity = Workflow::newActivityStub(LedgerPostActivity::class, $defaultOptions);
        $this->callbackActivity = Workflow::newActivityStub(MerchantCallbackActivity::class, $defaultOptions);
        $this->publishActivity = Workflow::newActivityStub(PublishDomainEventActivity::class, $defaultOptions);
    }

    /**
     * Dequeues the next unprocessed signal, skipping any duplicates by provider_event_id.
     * Returns null when no new signals remain in the queue.
     *
     * @return array<string, mixed>|null
     */
    private function consumeNextSignal(): ?array
    {
        while (! empty($this->signalQueue)) {
            $signal = array_shift($this->signalQueue);
            $eventId = $signal['provider_event_id'] ?? null;

            if ($eventId !== null && in_array($eventId, $this->processedEventIds, strict: true)) {
                Log::debug('Skipping duplicate refund signal', ['provider_event_id' => $eventId]);

                continue;
            }

            if ($eventId !== null) {
                $this->processedEventIds[] = $eventId;
            }

            return $signal;
        }

        return null;
    }

    /**
     * Returns true for provider statuses that indicate a successful refund outcome.
     */
    private function isRefundSuccessStatus(string $status): bool
    {
        return $status === 'refunded';
    }
}
