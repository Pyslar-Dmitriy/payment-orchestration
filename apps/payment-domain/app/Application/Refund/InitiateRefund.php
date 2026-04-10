<?php

namespace App\Application\Refund;

use App\Application\Refund\DTO\InitiateRefundCommand;
use App\Application\Refund\DTO\InitiateRefundResult;
use App\Domain\Refund\Refund;
use App\Domain\Refund\RefundStatus;
use App\Infrastructure\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;

final class InitiateRefund
{
    /**
     * Create a new refund record and enqueue a RefundInitiated outbox event —
     * all within a single database transaction.
     */
    public function execute(InitiateRefundCommand $command): InitiateRefundResult
    {
        return DB::transaction(function () use ($command): InitiateRefundResult {
            $refund = Refund::create([
                'payment_id' => $command->paymentId,
                'merchant_id' => $command->merchantId,
                'amount' => $command->amount,
                'currency' => $command->currency,
                'status' => RefundStatus::PENDING,
                'correlation_id' => $command->correlationId,
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
                    'status' => $refund->status->value,
                    'correlation_id' => $command->correlationId,
                    'occurred_at' => now()->toIso8601String(),
                ],
            ]);

            return new InitiateRefundResult(
                refundId: $refund->id,
                paymentId: $refund->payment_id,
                status: $refund->status->value,
                amount: $refund->amount,
                currency: $refund->currency,
            );
        });
    }
}