<?php

namespace App\Interfaces\Http\Controllers;

use App\Application\Refund\DTO\InitiateRefundCommand;
use App\Application\Refund\InitiateRefund;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Refund\Exceptions\RefundAmountExceededException;
use App\Interfaces\Http\Requests\InitiateRefundRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class InitiateRefundController
{
    public function __construct(private readonly InitiateRefund $initiateRefund) {}

    public function __invoke(InitiateRefundRequest $request): JsonResponse
    {
        $payment = Payment::where('id', $request->validated('payment_id'))
            ->where('merchant_id', $request->validated('merchant_id'))
            ->first();

        if ($payment === null) {
            return response()->json(['message' => 'Payment not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($payment->status !== PaymentStatus::CAPTURED) {
            return response()->json([
                'message' => 'Payment status does not allow a refund.',
                'errors' => ['payment_id' => ['Only captured payments can be refunded.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($request->validated('amount') > $payment->amount) {
            return response()->json([
                'message' => 'Refund amount exceeds the original payment amount.',
                'errors' => ['amount' => ['The refund amount must not exceed the original payment amount.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $command = new InitiateRefundCommand(
            paymentId: $payment->id,
            merchantId: $payment->merchant_id,
            amount: $request->validated('amount'),
            currency: $payment->currency,
            correlationId: $request->validated('correlation_id'),
        );

        try {
            $result = $this->initiateRefund->execute($command);
        } catch (RefundAmountExceededException) {
            return response()->json([
                'message' => 'Cumulative refund amount would exceed the original payment amount.',
                'errors' => ['amount' => ['The total refunded amount must not exceed the original payment amount.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json($result, Response::HTTP_CREATED);
    }
}
