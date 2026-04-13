<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use App\Domain\DTO\PaymentWorkflowInput;
use Generator;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

/**
 * Orchestrates the full lifecycle of a single payment:
 *   provider routing → authorize+capture → webhook wait → ledger → callback.
 *
 * Workflow ID convention: payment_uuid (unique per payment — never reused).
 * Task queue: config('temporal.task_queue') — set by the client when starting.
 */
#[WorkflowInterface]
interface PaymentWorkflow
{
    /**
     * Main workflow entry point. Runs to completion or a terminal compensation state.
     */
    #[WorkflowMethod(name: 'PaymentWorkflow')]
    public function run(PaymentWorkflowInput $input): Generator;

    /**
     * Inbound signal from webhook-normalizer when the provider confirms authorization.
     *
     * Expected payload keys: provider_event_id, provider_status, provider_reference, correlation_id.
     */
    #[SignalMethod(name: 'provider.authorization_result')]
    public function onAuthorizationResult(array $payload): void;

    /**
     * Inbound signal from webhook-normalizer when the provider confirms capture.
     *
     * Expected payload keys: provider_event_id, provider_status, provider_reference, correlation_id.
     */
    #[SignalMethod(name: 'provider.capture_result')]
    public function onCaptureResult(array $payload): void;
}
