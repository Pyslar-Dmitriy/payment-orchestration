<?php

namespace App\Interfaces\Http\Controllers;

use App\Application\Refund\InitiateRefund;
use App\Domain\Payment\Payment;
use App\Interfaces\Http\Requests\InitiateRefundRequest;
use Illuminate\Http\JsonResponse;

final class InitiateRefundController
{
    public function __construct(private readonly InitiateRefund $initiateRefund) {}

    public function __invoke(InitiateRefundRequest $request): JsonResponse
    {
        $payment = Payment::where('id', $request->validated('payment_id'))
            ->where('merchant_id', $request->validated('merchant_id'))
            ->first();

        if ($payment === null) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        if ($payment->status !== 'captured') {
            return response()->json([
                'message' => 'Payment status does not allow a refund.',
                'errors' => ['payment_id' => ['Only captured payments can be refunded.']],
            ], 422);
        }

        if ($request->validated('amount') > $payment->amount) {
            return response()->json([
                'message' => 'Refund amount exceeds the original payment amount.',
                'errors' => ['amount' => ['The refund amount must not exceed the original payment amount.']],
            ], 422);
        }

        $result = $this->initiateRefund->execute([
            'payment_id' => $payment->id,
            'merchant_id' => $payment->merchant_id,
            'amount' => $request->validated('amount'),
            'currency' => $payment->currency,
            'correlation_id' => $request->validated('correlation_id'),
        ]);

        return response()->json($result, 201);
    }
}
