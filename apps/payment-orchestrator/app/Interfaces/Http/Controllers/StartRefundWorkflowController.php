<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Domain\DTO\RefundWorkflowInput;
use App\Domain\Workflow\RefundWorkflow;
use App\Interfaces\Http\Requests\StartRefundWorkflowRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;

final class StartRefundWorkflowController
{
    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    public function __invoke(StartRefundWorkflowRequest $request): JsonResponse
    {
        $input = new RefundWorkflowInput(
            refundUuid: $request->validated('refund_uuid'),
            paymentUuid: $request->validated('payment_uuid'),
            merchantId: $request->validated('merchant_id'),
            amount: $request->validated('amount'),
            currency: $request->validated('currency'),
            providerKey: $request->validated('provider_key'),
            correlationId: $request->validated('correlation_id'),
        );

        $stub = $this->workflowClient->newWorkflowStub(
            RefundWorkflow::class,
            WorkflowOptions::new()
                ->withWorkflowId($input->refundUuid)
                ->withTaskQueue(config('temporal.task_queue'))
                ->withWorkflowRunTimeout('2 hours'),
        );

        try {
            $this->workflowClient->start($stub, $input);
        } catch (WorkflowExecutionAlreadyStartedException) {
            return response()->json(
                ['message' => 'A workflow for this refund is already running.'],
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json(['workflow_id' => $input->refundUuid], Response::HTTP_CREATED);
    }
}