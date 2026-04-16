<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use App\Domain\Activity\LedgerPostActivity;
use App\Domain\Activity\MerchantCallbackActivity;
use App\Domain\Activity\ProviderCallActivity;
use App\Domain\Activity\ProviderRoutingActivity;
use App\Domain\Activity\ProviderStatusQueryActivity;
use App\Domain\Activity\PublishDomainEventActivity;
use App\Domain\Activity\UpdatePaymentStatusActivity;
use App\Domain\DTO\PaymentWorkflowInput;
use App\Domain\DTO\ProviderCallResult;
use App\Domain\DTO\ProviderStatusResult;
use Generator;
use Illuminate\Support\Facades\Log;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Exception\Failure\ActivityFailure;
use Temporal\Workflow;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\WorkflowMethod;

class PaymentWorkflowImpl implements PaymentWorkflow
{
    private const WEBHOOK_TIMEOUT_SECONDS = 1800; // 30 minutes

    /** @var list<array<string, mixed>> Incoming signals buffered in arrival order. */
    private array $signalQueue = [];

    /** @var list<string> Event IDs that have already been processed (deduplication). */
    private array $processedEventIds = [];

    /** True once a successful provider.authorization_result signal has been processed. */
    private bool $authorizationReceived = false;

    // Activity stubs — initialised once per workflow execution in initActivities().
    private ProviderRoutingActivity $routingActivity;

    private ProviderCallActivity $providerCallActivity;

    private ProviderStatusQueryActivity $providerQueryActivity;

    private UpdatePaymentStatusActivity $updateStatusActivity;

    private LedgerPostActivity $ledgerActivity;

    private MerchantCallbackActivity $callbackActivity;

    private PublishDomainEventActivity $publishActivity;

    // ── Signal handlers ────────────────────────────────────────────────────────

    #[SignalMethod(name: 'provider.authorization_result')]
    public function onAuthorizationResult(array $payload): void
    {
        $this->signalQueue[] = array_merge($payload, ['signal_type' => 'authorization_result']);
    }

    #[SignalMethod(name: 'provider.capture_result')]
    public function onCaptureResult(array $payload): void
    {
        $this->signalQueue[] = array_merge($payload, ['signal_type' => 'capture_result']);
    }

    // ── Main workflow ──────────────────────────────────────────────────────────

    #[WorkflowMethod(name: 'PaymentWorkflow')]
    public function run(PaymentWorkflowInput $input): Generator
    {
        $this->initActivities();

        // Step 1: Transition payment to pending_provider.
        yield $this->updateStatusActivity->markPendingProvider($input->paymentUuid, $input->correlationId);

        // Steps 2–3: Route to a provider and call it (with one fallback attempt on hard failure).
        $providerResult = yield from $this->routeAndCallProvider($input);

        if ($providerResult === null) {
            return;
        }

        [$providerKey, $callResult] = $providerResult;

        // Step 4: Synchronous provider — result is already known, no webhook wait needed.
        if (! $callResult->isAsync) {
            yield from $this->handleSyncProviderResult($callResult, $input);

            return;
        }

        // Steps 5–7: Wait for async webhook signals, recover from timeout, evaluate capture.
        $captureStatus = yield from $this->awaitCaptureConfirmation(
            $providerKey, $callResult->providerStatus, $input,
        );

        if ($captureStatus === null) {
            return;
        }

        // Step 8: Happy path — update status, post to ledger, publish event, trigger callback.
        yield from $this->proceedToCaptureAndLedger($input, $captureStatus);
    }

    // ── Private workflow steps ─────────────────────────────────────────────────

    /**
     * Selects a provider and calls it, retrying with one fallback provider on hard failure.
     *
     * Returns [providerKey, ProviderCallResult] on success.
     * Returns null after handling a Class A failure internally when no provider is available.
     *
     * @return Generator<mixed, mixed, mixed, array{0: string, 1: ProviderCallResult}|null>
     */
    private function routeAndCallProvider(PaymentWorkflowInput $input): Generator
    {
        $excludedProviders = [];

        try {
            $providerKey = yield $this->routingActivity->selectProvider(
                $input->paymentUuid, $input->currency, $input->country, $excludedProviders,
            );
        } catch (ActivityFailure) {
            yield from $this->handleClassAFailure($input, 'no_provider_available');

            return null;
        }

        try {
            $callResult = yield $this->providerCallActivity->authorizeAndCapture(
                $input->paymentUuid, $providerKey, $input->correlationId,
            );

            return [$providerKey, $callResult];
        } catch (ActivityFailure) {
            // Primary provider failed hard — try one fallback.
        }

        $excludedProviders[] = $providerKey;

        try {
            $fallbackKey = yield $this->routingActivity->selectProvider(
                $input->paymentUuid, $input->currency, $input->country, $excludedProviders,
            );
            $callResult = yield $this->providerCallActivity->authorizeAndCapture(
                $input->paymentUuid, $fallbackKey, $input->correlationId,
            );

            return [$fallbackKey, $callResult];
        } catch (ActivityFailure) {
            yield from $this->handleClassAFailure($input, 'provider_hard_failure');

            return null;
        }
    }

    /**
     * Handles the result when the provider answered synchronously (no webhook wait needed).
     */
    private function handleSyncProviderResult(ProviderCallResult $callResult, PaymentWorkflowInput $input): Generator
    {
        if (! $this->isProviderSuccessStatus($callResult->providerStatus)) {
            yield from $this->handleClassAFailure($input, $callResult->providerStatus);

            return;
        }

        yield from $this->proceedToCaptureAndLedger($input, $callResult->providerStatus);
    }

    /**
     * Waits for provider webhook signals and handles timeout recovery.
     *
     * Returns the capture status string on success.
     * Returns null after handling failure internally when capture cannot be confirmed.
     *
     * @return Generator<mixed, mixed, mixed, string|null>
     */
    private function awaitCaptureConfirmation(
        string $providerKey,
        string $initialProviderStatus,
        PaymentWorkflowInput $input,
    ): Generator {
        $startTime = Workflow::now();
        $captureStatus = null;

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

            if ($signal['signal_type'] === 'authorization_result') {
                if ($this->isProviderSuccessStatus($signal['provider_status'] ?? '')) {
                    $this->authorizationReceived = true;
                    yield $this->updateStatusActivity->markAuthorized($input->paymentUuid, $input->correlationId);
                } else {
                    yield from $this->handleClassAFailure($input, $signal['provider_status'] ?? 'failed');

                    return null;
                }

                continue;
            }

            if ($signal['signal_type'] === 'capture_result') {
                $captureStatus = $signal['provider_status'] ?? '';
                break;
            }
        }

        // Signal loop exited without a capture result — query the provider before deciding.
        if ($captureStatus === null) {
            $captureStatus = yield from $this->resolveAfterTimeout(
                $providerKey, $initialProviderStatus, $input,
            );

            if ($captureStatus === null) {
                return null;
            }
        }

        if (! $this->isProviderSuccessStatus($captureStatus)) {
            yield from $this->handleClassAFailure($input, $captureStatus);

            return null;
        }

        return $captureStatus;
    }

    /**
     * Queries the provider after a signal timeout to recover the capture outcome.
     *
     * Returns the provider status string when capture is confirmed.
     * Returns null after handling failure internally when the outcome is negative or ambiguous.
     *
     * @return Generator<mixed, mixed, mixed, string|null>
     */
    private function resolveAfterTimeout(
        string $providerKey,
        string $initialProviderStatus,
        PaymentWorkflowInput $input,
    ): Generator {
        try {
            /** @var ProviderStatusResult $queryResult */
            $queryResult = yield $this->providerQueryActivity->queryStatus(
                $input->paymentUuid, $providerKey, $input->correlationId,
            );
        } catch (ActivityFailure $e) {
            // If authorization was already confirmed (Class B per ADR-010), apply compensation.
            if ($this->authorizationReceived) {
                yield from $this->handleClassBFailure(
                    $input, 'provider_status_query', $initialProviderStatus, $e->getMessage(),
                );
            } else {
                yield from $this->handleClassAFailure($input, 'timeout_query_failure');
            }

            return null;
        }

        if ($queryResult->isCaptured) {
            return $queryResult->providerStatus;
        }

        if ($queryResult->isAuthorized) {
            // Authorization confirmed by provider but capture not yet complete.
            // Treat as if the authorization signal arrived — mark authorized and continue the flow.
            $this->authorizationReceived = true;
            yield $this->updateStatusActivity->markAuthorized($input->paymentUuid, $input->correlationId);

            return $queryResult->providerStatus;
        }

        if ($queryResult->isFailed) {
            yield from $this->handleClassAFailure($input, $queryResult->providerStatus);

            return null;
        }

        // Ambiguous / unknown status — fail safe (Class A, no funds confirmed moved).
        yield from $this->handleClassAFailure($input, 'timeout_unknown_provider_status');

        return null;
    }

    /**
     * Happy-path completion: mark captured → post to ledger → publish → callback.
     * On permanent ledger failure, applies ADR-010 Class B compensation instead.
     */
    private function proceedToCaptureAndLedger(PaymentWorkflowInput $input, string $captureStatus): Generator
    {
        yield $this->updateStatusActivity->markCaptured($input->paymentUuid, $input->correlationId);

        try {
            yield $this->ledgerActivity->postCapture($input->paymentUuid, $input->correlationId);
        } catch (ActivityFailure $e) {
            // Ledger failed permanently after confirmed provider capture — ADR-010 Class B.
            yield from $this->handleClassBFailure($input, 'ledger_post', $captureStatus, $e->getMessage());

            return;
        }

        yield $this->publishActivity->publishPaymentCaptured($input->paymentUuid, $input->correlationId);
        yield $this->callbackActivity->triggerCallback($input->paymentUuid, 'captured', $input->correlationId);
    }

    /**
     * ADR-010 Class A: no external side effect — mark failed, publish, callback.
     */
    private function handleClassAFailure(PaymentWorkflowInput $input, string $reason): Generator
    {
        yield $this->updateStatusActivity->markFailed($input->paymentUuid, $input->correlationId, $reason);
        yield $this->publishActivity->publishPaymentFailed($input->paymentUuid, $input->correlationId);
        yield $this->callbackActivity->triggerCallback($input->paymentUuid, 'failed', $input->correlationId);
    }

    /**
     * ADR-010 Class B: external side effect already occurred — mark requires_reconciliation,
     * publish operator alert event. No merchant callback — manual intervention required.
     */
    private function handleClassBFailure(
        PaymentWorkflowInput $input,
        string $failedStep,
        string $lastKnownProviderStatus,
        string $failureReason,
    ): Generator {
        Log::error('Payment requires reconciliation — manual intervention needed', [
            'alert' => true,
            'payment_id' => $input->paymentUuid,
            'correlation_id' => $input->correlationId,
            'failed_step' => $failedStep,
        ]);

        yield $this->updateStatusActivity->markRequiresReconciliation(
            $input->paymentUuid, $input->correlationId, $failedStep,
        );
        yield $this->publishActivity->publishPaymentRequiresReconciliation(
            $input->paymentUuid,
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

        $this->routingActivity = Workflow::newActivityStub(ProviderRoutingActivity::class, $defaultOptions);
        $this->providerCallActivity = Workflow::newActivityStub(ProviderCallActivity::class, $providerCallOptions);
        $this->providerQueryActivity = Workflow::newActivityStub(ProviderStatusQueryActivity::class, $defaultOptions);
        $this->updateStatusActivity = Workflow::newActivityStub(UpdatePaymentStatusActivity::class, $defaultOptions);
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
                Log::debug('Skipping duplicate signal', ['provider_event_id' => $eventId]);

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
     * Returns true for provider statuses that indicate a successful outcome.
     * Values map to the internal status vocabulary (see TASK-091 for normalization).
     */
    private function isProviderSuccessStatus(string $status): bool
    {
        return in_array($status, ['authorized', 'captured'], strict: true);
    }
}
