<?php

namespace App\Application\Payment;

use App\Application\Payment\DTO\GetPaymentResult;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatusHistory;

final class GetPayment
{
    public function execute(string $paymentId, string $merchantId): ?GetPaymentResult
    {
        $payment = Payment::where('id', $paymentId)
            ->where('merchant_id', $merchantId)
            ->first();

        if ($payment === null) {
            return null;
        }

        $failureReason = PaymentStatusHistory::where('payment_id', $payment->id)
            ->where('to_status', 'failed')
            ->latest('id')
            ->value('reason');

        // TODO: once state-transition use cases write failure_reason directly to the payments
        // row, replace $failureReason with $payment->failure_reason to avoid the join.
        return new GetPaymentResult(
            paymentId: $payment->id,
            status: $payment->status->value,
            amount: $payment->amount,
            currency: $payment->currency,
            providerReference: $payment->provider_transaction_id,
            failureReason: $failureReason,
            createdAt: $payment->created_at->toIso8601String(),
            updatedAt: $payment->updated_at->toIso8601String(),
        );
    }
}
