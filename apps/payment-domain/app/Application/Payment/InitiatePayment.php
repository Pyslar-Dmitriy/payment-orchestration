<?php

namespace App\Application\Payment;

use App\Application\Payment\DTO\InitiatePaymentCommand;
use App\Application\Payment\DTO\InitiatePaymentResult;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentAttempt;
use App\Domain\Payment\PaymentAttemptStatus;
use App\Domain\Payment\PaymentStatus;
use App\Domain\Payment\PaymentStatusHistory;
use App\Infrastructure\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;

final class InitiatePayment
{
    /**
     * Create a new payment in `created` status, record the first status history
     * entry, create the initial payment attempt, and enqueue a PaymentCreated
     * event via the outbox — all in one database transaction.
     *
     * If a payment with the same `idempotency_key` already exists, the existing
     * record is returned immediately without writing anything (idempotent replay).
     */
    public function execute(InitiatePaymentCommand $command): InitiatePaymentResult
    {
        $existing = Payment::where('idempotency_key', $command->idempotencyKey)
            ->where('merchant_id', $command->merchantId)
            ->first();

        if ($existing !== null) {
            $existingAttempt = $existing->attempts()->orderBy('attempt_number')->first();

            return new InitiatePaymentResult(
                paymentId: $existing->id,
                attemptId: $existingAttempt?->id,
                status: $existing->status->value,
                created: false,
            );
        }

        return DB::transaction(function () use ($command): InitiatePaymentResult {
            $payment = Payment::create([
                'merchant_id' => $command->merchantId,
                'amount' => $command->amount,
                'currency' => $command->currency,
                'external_reference' => $command->externalReference,
                'idempotency_key' => $command->idempotencyKey,
                'provider_id' => $command->providerId,
                'customer_reference' => $command->customerReference,
                'payment_method_reference' => $command->paymentMethodReference,
                'metadata' => $command->metadata,
                'status' => PaymentStatus::CREATED,
                'correlation_id' => $command->correlationId,
            ]);

            PaymentStatusHistory::create([
                'payment_id' => $payment->id,
                'from_status' => null,
                'to_status' => PaymentStatus::CREATED,
                'correlation_id' => $command->correlationId,
            ]);

            $attempt = PaymentAttempt::create([
                'payment_id' => $payment->id,
                'attempt_number' => 1,
                'provider_id' => $command->providerId,
                'status' => PaymentAttemptStatus::PENDING,
                'correlation_id' => $command->correlationId,
            ]);

            OutboxEvent::create([
                'aggregate_type' => 'Payment',
                'aggregate_id' => $payment->id,
                'event_type' => 'payment.initiated.v1',
                'payload' => [
                    'payment_id' => $payment->id,
                    'attempt_id' => $attempt->id,
                    'merchant_id' => $payment->merchant_id,
                    'provider_id' => $payment->provider_id,
                    'amount' => ['value' => $payment->amount, 'currency' => $payment->currency],
                    'external_reference' => $payment->external_reference,
                    'customer_reference' => $payment->customer_reference,
                    'status' => $payment->status->value,
                    'correlation_id' => $command->correlationId,
                    'occurred_at' => now()->toIso8601String(),
                ],
            ]);

            return new InitiatePaymentResult(
                paymentId: $payment->id,
                attemptId: $attempt->id,
                status: $payment->status->value,
                created: true,
            );
        });
    }
}
