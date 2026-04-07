<?php

namespace App\Application\Payment;

use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatusHistory;
use App\Infrastructure\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;

final class InitiatePayment
{
    /**
     * Create a new payment in `initiated` status, record the first status history
     * entry, and enqueue a PaymentInitiated event via the outbox — all in one
     * database transaction.
     *
     * @param array{
     *   merchant_id: string,
     *   amount: int,
     *   currency: string,
     *   external_reference: string,
     *   customer_reference: string|null,
     *   payment_method_reference: string|null,
     *   metadata: array|null,
     *   correlation_id: string,
     * } $data
     * @return array{payment_id: string, status: string}
     */
    public function execute(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $payment = Payment::create([
                'merchant_id' => $data['merchant_id'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'external_reference' => $data['external_reference'],
                'customer_reference' => $data['customer_reference'] ?? null,
                'payment_method_reference' => $data['payment_method_reference'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'status' => 'initiated',
                'correlation_id' => $data['correlation_id'],
            ]);

            PaymentStatusHistory::create([
                'payment_id' => $payment->id,
                'from_status' => null,
                'to_status' => 'initiated',
                'correlation_id' => $data['correlation_id'],
            ]);

            OutboxEvent::create([
                'aggregate_type' => 'Payment',
                'aggregate_id' => $payment->id,
                'event_type' => 'payment.initiated.v1',
                'payload' => [
                    'payment_id' => $payment->id,
                    'merchant_id' => $payment->merchant_id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'external_reference' => $payment->external_reference,
                    'customer_reference' => $payment->customer_reference,
                    'status' => $payment->status,
                    'correlation_id' => $data['correlation_id'],
                    'occurred_at' => now()->toIso8601String(),
                ],
            ]);

            return [
                'payment_id' => $payment->id,
                'status' => $payment->status,
            ];
        });
    }
}
