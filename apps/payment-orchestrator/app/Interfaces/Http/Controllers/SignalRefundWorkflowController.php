<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Domain\Workflow\RefundWorkflow;
use App\Interfaces\Http\Requests\SignalRefundWorkflowRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Exception\Client\ServiceClientException;
use Temporal\Exception\Client\WorkflowNotFoundException;

final class SignalRefundWorkflowController
{
    public function __construct(private readonly WorkflowClientInterface $workflowClient) {}

    public function __invoke(SignalRefundWorkflowRequest $request, string $workflowId): JsonResponse
    {
        $payload = [
            'provider_event_id' => $request->validated('provider_event_id'),
            'provider_status' => $request->validated('provider_status'),
            'provider_reference' => $request->validated('provider_reference'),
            'correlation_id' => $request->validated('correlation_id'),
        ];

        try {
            /** @var RefundWorkflow $stub */
            $stub = $this->workflowClient->newRunningWorkflowStub(RefundWorkflow::class, $workflowId);

            $stub->onRefundResult($payload);
        } catch (WorkflowNotFoundException) {
            return response()->json(['message' => 'Workflow not found.'], Response::HTTP_NOT_FOUND);
        } catch (ServiceClientException $e) {
            // gRPC NOT_FOUND status code = 5
            if ($e->getCode() === 5) {
                return response()->json(['message' => 'Workflow not found.'], Response::HTTP_NOT_FOUND);
            }

            throw $e;
        }

        return response()->json(['message' => 'Signal accepted.'], Response::HTTP_OK);
    }
}
