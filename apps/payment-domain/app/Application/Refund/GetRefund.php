<?php

namespace App\Application\Refund;

use App\Application\Refund\DTO\GetRefundResult;
use App\Domain\Refund\Refund;

final class GetRefund
{
    public function execute(string $refundId, string $merchantId): ?GetRefundResult
    {
        $refund = Refund::where('id', $refundId)
            ->where('merchant_id', $merchantId)
            ->first();

        if ($refund === null) {
            return null;
        }

        return new GetRefundResult(
            refundId: $refund->id,
            paymentId: $refund->payment_id,
            status: $refund->status->value,
            amount: $refund->amount,
            currency: $refund->currency,
            correlationId: $refund->correlation_id,
            createdAt: $refund->created_at->toIso8601String(),
            updatedAt: $refund->updated_at->toIso8601String(),
        );
    }
}
