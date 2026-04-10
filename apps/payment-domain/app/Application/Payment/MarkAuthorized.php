<?php

namespace App\Application\Payment;

use App\Application\Payment\DTO\UpdatePaymentStatusCommand;
use App\Application\Payment\DTO\UpdatePaymentStatusResult;
use App\Domain\Payment\Exceptions\PaymentNotFoundException;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Infrastructure\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;

final class MarkAuthorized
{
    public function execute(UpdatePaymentStatusCommand $command): UpdatePaymentStatusResult
    {
        $payment = Payment::where('id', $command->paymentId)
            ->where('merchant_id', $command->merchantId)
            ->first();

        if ($payment === null) {
            throw new PaymentNotFoundException($command->paymentId);
        }

        return DB::transaction(function () use ($payment, $command): UpdatePaymentStatusResult {
            $payment->transition(
                PaymentStatus::AUTHORIZED,
                $command->correlationId,
                $command->reason,
            );

            OutboxEvent::create([
                'aggregate_type' => 'Payment',
                'aggregate_id' => $payment->id,
                'event_type' => 'payment.authorized.v1',
                'payload' => [
                    'payment_id' => $payment->id,
                    'merchant_id' => $payment->merchant_id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status->value,
                    'correlation_id' => $command->correlationId,
                    'occurred_at' => now()->toIso8601String(),
                ],
            ]);

            return new UpdatePaymentStatusResult(
                paymentId: $payment->id,
                status: $payment->status->value,
            );
        });
    }
}
