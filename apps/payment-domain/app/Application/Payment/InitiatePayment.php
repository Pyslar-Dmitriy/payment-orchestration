<?php

namespace App\Application\Payment;

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
     *
     * @param array{
     *   merchant_id: string,
     *   amount: int,
     *   currency: string,
     *   external_reference: string,
     *   idempotency_key: string,
     *   provider_id: string,
     *   customer_reference: string|null,
     *   payment_method_reference: string|null,
     *   metadata: array|null,
     *   correlation_id: string,
     * } $data
     * @return array{payment_id: string, attempt_id: string, status: string, created: bool}
     */
    public function execute(array $data): array
    {
        $existing = Payment::where('idempotency_key', $data['idempotency_key'])->first();

        if ($existing !== null) {
            $existingAttempt = $existing->attempts()->orderBy('attempt_number')->first();

            return [
                'payment_id' => $existing->id,
                'attempt_id' => $existingAttempt?->id,
                'status' => $existing->status->value,
                'created' => false,
            ];
        }

        return DB::transaction(function () use ($data): array {
            $payment = Payment::create([
                'merchant_id' => $data['merchant_id'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'external_reference' => $data['external_reference'],
                'idempotency_key' => $data['idempotency_key'],
                'provider_id' => $data['provider_id'],
                'customer_reference' => $data['customer_reference'] ?? null,
                'payment_method_reference' => $data['payment_method_reference'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'status' => PaymentStatus::CREATED,
                'correlation_id' => $data['correlation_id'],
            ]);

            PaymentStatusHistory::create([
                'payment_id' => $payment->id,
                'from_status' => null,
                'to_status' => PaymentStatus::CREATED,
                'correlation_id' => $data['correlation_id'],
            ]);

            $attempt = PaymentAttempt::create([
                'payment_id' => $payment->id,
                'attempt_number' => 1,
                'provider_id' => $data['provider_id'],
                'status' => PaymentAttemptStatus::PENDING,
                'correlation_id' => $data['correlation_id'],
            ]);

            OutboxEvent::create([
                'aggregate_type' => 'Payment',
                'aggregate_id' => $payment->id,
                'event_type' => 'payment.initiated.v1',
                'payload' => [
                    'payment_id' => $payment->id,
                    'attempt_id' => $attempt->id,
                    'merchant_id' => $payment->merchant_id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'external_reference' => $payment->external_reference,
                    'customer_reference' => $payment->customer_reference,
                    'provider_id' => $payment->provider_id,
                    'status' => $payment->status->value,
                    'correlation_id' => $data['correlation_id'],
                    'occurred_at' => now()->toIso8601String(),
                ],
            ]);

            return [
                'payment_id' => $payment->id,
                'attempt_id' => $attempt->id,
                'status' => $payment->status->value,
                'created' => true,
            ];
        });
    }
}