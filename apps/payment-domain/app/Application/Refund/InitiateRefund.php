<?php

namespace App\Application\Refund;

use App\Domain\Refund\Refund;
use App\Infrastructure\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;

final class InitiateRefund
{
    /**
     * Create a new refund record and enqueue a RefundInitiated outbox event —
     * all within a single database transaction.
     *
     * @param array{
     *   payment_id: string,
     *   merchant_id: string,
     *   amount: int,
     *   currency: string,
     *   correlation_id: string,
     * } $data
     * @return array{refund_id: string, payment_id: string, status: string, amount: int, currency: string}
     */
    public function execute(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $refund = Refund::create([
                'payment_id' => $data['payment_id'],
                'merchant_id' => $data['merchant_id'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'status' => 'pending',
                'correlation_id' => $data['correlation_id'],
            ]);

            OutboxEvent::create([
                'aggregate_type' => 'Refund',
                'aggregate_id' => $refund->id,
                'event_type' => 'refund.initiated.v1',
                'payload' => [
                    'refund_id' => $refund->id,
                    'payment_id' => $refund->payment_id,
                    'merchant_id' => $refund->merchant_id,
                    'amount' => $refund->amount,
                    'currency' => $refund->currency,
                    'status' => $refund->status,
                    'correlation_id' => $data['correlation_id'],
                    'occurred_at' => now()->toIso8601String(),
                ],
            ]);

            return [
                'refund_id' => $refund->id,
                'payment_id' => $refund->payment_id,
                'status' => $refund->status,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
            ];
        });
    }
}
