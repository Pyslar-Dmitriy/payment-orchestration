<?php

namespace App\Interfaces\Http\Controllers;

use App\Application\Payment\DTO\InternalUpdatePaymentStatusCommand;
use App\Application\Payment\InternalMarkPaymentStatus;
use App\Domain\Payment\Exceptions\InvalidPaymentTransitionException;
use App\Domain\Payment\Exceptions\PaymentConcurrencyException;
use App\Domain\Payment\Exceptions\PaymentNotFoundException;
use App\Domain\Payment\PaymentStatus;
use App\Interfaces\Http\Requests\InternalTransitionPaymentStatusRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class InternalTransitionPaymentStatusController
{
    public function __construct(private readonly InternalMarkPaymentStatus $markStatus) {}

    public function __invoke(InternalTransitionPaymentStatusRequest $request, string $id): JsonResponse
    {
        $command = new InternalUpdatePaymentStatusCommand(
            paymentId: $id,
            correlationId: $request->validated('correlation_id'),
            failedStep: $request->validated('failed_step'),
            failureCode: $request->validated('failure_code'),
            failureReason: $request->validated('failure_reason'),
        );

        $status = PaymentStatus::from($request->validated('status'));

        try {
            $result = $this->markStatus->execute($command, $status);
        } catch (PaymentNotFoundException) {
            return response()->json(['message' => 'Payment not found.'], Response::HTTP_NOT_FOUND);
        } catch (InvalidPaymentTransitionException $e) {
            return response()->json([
                'message' => 'Invalid status transition.',
                'errors' => ['status' => [$e->getMessage()]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (PaymentConcurrencyException) {
            return response()->json([
                'message' => 'Payment was modified concurrently. Please retry.',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json($result, Response::HTTP_OK);
    }
}
