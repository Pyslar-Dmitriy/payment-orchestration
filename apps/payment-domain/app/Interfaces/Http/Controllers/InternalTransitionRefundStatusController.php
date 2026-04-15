<?php

namespace App\Interfaces\Http\Controllers;

use App\Application\Refund\DTO\InternalUpdateRefundStatusCommand;
use App\Application\Refund\InternalMarkRefundStatus;
use App\Domain\Refund\Exceptions\InvalidRefundTransitionException;
use App\Domain\Refund\Exceptions\RefundNotFoundException;
use App\Domain\Refund\RefundStatus;
use App\Interfaces\Http\Requests\InternalTransitionRefundStatusRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class InternalTransitionRefundStatusController
{
    public function __construct(private readonly InternalMarkRefundStatus $markStatus) {}

    public function __invoke(InternalTransitionRefundStatusRequest $request, string $id): JsonResponse
    {
        $command = new InternalUpdateRefundStatusCommand(
            refundId: $id,
            correlationId: $request->validated('correlation_id'),
            failedStep: $request->validated('failed_step'),
            failureReason: $request->validated('failure_reason'),
        );

        $status = RefundStatus::from($request->validated('status'));

        try {
            $result = $this->markStatus->execute($command, $status);
        } catch (RefundNotFoundException) {
            return response()->json(['message' => 'Refund not found.'], Response::HTTP_NOT_FOUND);
        } catch (InvalidRefundTransitionException $e) {
            return response()->json([
                'message' => 'Invalid status transition.',
                'errors' => ['status' => [$e->getMessage()]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json($result, Response::HTTP_OK);
    }
}
