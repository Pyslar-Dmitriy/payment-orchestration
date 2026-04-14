<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use App\Domain\DTO\RefundWorkflowInput;
use Generator;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

/**
 * Orchestrates the full lifecycle of a single refund:
 *   provider refund call → webhook wait → ledger reversal → callback.
 *
 * Workflow ID convention: refund_uuid (unique per refund — never reused).
 * Task queue: config('temporal.task_queue') — set by the client when starting.
 */
#[WorkflowInterface]
interface RefundWorkflow
{
    /**
     * Main workflow entry point. Runs to completion or a terminal compensation state.
     */
    #[WorkflowMethod(name: 'RefundWorkflow')]
    public function run(RefundWorkflowInput $input): Generator;

    /**
     * Inbound signal from webhook-normalizer when the provider confirms the refund.
     *
     * Expected payload keys: provider_event_id, provider_status, provider_reference, correlation_id.
     */
    #[SignalMethod(name: 'provider.refund_result')]
    public function onRefundResult(array $payload): void;
}