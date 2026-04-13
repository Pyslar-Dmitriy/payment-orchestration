<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Domain\DTO\PaymentWorkflowInput;
use App\Domain\Workflow\PaymentWorkflow;
use App\Interfaces\Http\Requests\StartPaymentWorkflowRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;

final class StartPaymentWorkflowController
{
    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    public function __invoke(StartPaymentWorkflowRequest $request): JsonResponse
    {
        $input = new PaymentWorkflowInput(
            paymentUuid: $request->validated('payment_uuid'),
            merchantId: $request->validated('merchant_id'),
            amount: $request->validated('amount'),
            currency: $request->validated('currency'),
            country: $request->validated('country'),
            correlationId: $request->validated('correlation_id'),
        );

        $stub = $this->workflowClient->newWorkflowStub(
            PaymentWorkflow::class,
            WorkflowOptions::new()
                ->withWorkflowId($input->paymentUuid)
                ->withTaskQueue(config('temporal.task_queue'))
                ->withWorkflowRunTimeout('2 hours'),
        );

        try {
            $this->workflowClient->start($stub, $input);
        } catch (WorkflowExecutionAlreadyStartedException) {
            return response()->json(
                ['message' => 'A workflow for this payment is already running.'],
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json(['workflow_id' => $input->paymentUuid], Response::HTTP_CREATED);
    }
}
