<?php

namespace App\Interfaces\Http\Controllers;

use App\Application\Payment\DTO\UpdatePaymentStatusCommand;
use App\Application\Payment\MarkAuthorized;
use App\Application\Payment\MarkCaptured;
use App\Application\Payment\MarkFailed;
use App\Application\Payment\MarkPendingProvider;
use App\Application\Payment\MarkRefunded;
use App\Application\Payment\MarkRefunding;
use App\Domain\Payment\Exceptions\InvalidPaymentTransitionException;
use App\Domain\Payment\Exceptions\PaymentConcurrencyException;
use App\Domain\Payment\Exceptions\PaymentNotFoundException;
use App\Domain\Payment\PaymentStatus;
use App\Interfaces\Http\Requests\TransitionPaymentStatusRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class TransitionPaymentStatusController
{
    public function __construct(
        private readonly MarkPendingProvider $markPendingProvider,
        private readonly MarkAuthorized $markAuthorized,
        private readonly MarkCaptured $markCaptured,
        private readonly MarkFailed $markFailed,
        private readonly MarkRefunding $markRefunding,
        private readonly MarkRefunded $markRefunded,
    ) {}

    public function __invoke(TransitionPaymentStatusRequest $request, string $id): JsonResponse
    {
        $command = new UpdatePaymentStatusCommand(
            paymentId: $id,
            merchantId: $request->validated('merchant_id'),
            correlationId: $request->validated('correlation_id'),
            reason: $request->validated('reason'),
            failureCode: $request->validated('failure_code'),
            failureReason: $request->validated('failure_reason'),
        );

        $status = PaymentStatus::from($request->validated('status'));

        try {
            $result = match ($status) {
                PaymentStatus::PENDING_PROVIDER => $this->markPendingProvider->execute($command),
                PaymentStatus::AUTHORIZED => $this->markAuthorized->execute($command),
                PaymentStatus::CAPTURED => $this->markCaptured->execute($command),
                PaymentStatus::FAILED => $this->markFailed->execute($command),
                PaymentStatus::REFUNDING => $this->markRefunding->execute($command),
                PaymentStatus::REFUNDED => $this->markRefunded->execute($command),
            };
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
