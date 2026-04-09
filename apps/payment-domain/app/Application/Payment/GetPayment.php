<?php

namespace App\Application\Payment;

use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatusHistory;

final class GetPayment
{
    /**
     * @return array{
     *   payment_id: string,
     *   status: string,
     *   amount: int,
     *   currency: string,
     *   provider_reference: string|null,
     *   failure_reason: string|null,
     *   created_at: string,
     *   updated_at: string,
     * }|null
     */
    public function execute(string $paymentId, string $merchantId): ?array
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
        return [
            'payment_id' => $payment->id,
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'provider_reference' => $payment->provider_transaction_id,
            'failure_reason' => $failureReason,
            'created_at' => $payment->created_at->toIso8601String(),
            'updated_at' => $payment->updated_at->toIso8601String(),
        ];
    }
}
