<?php

namespace App\Application\Refund;

use App\Domain\Refund\Refund;

final class GetRefund
{
    /**
     * @return array{
     *   refund_id: string,
     *   payment_id: string,
     *   status: string,
     *   amount: int,
     *   currency: string,
     *   correlation_id: string,
     *   created_at: string,
     *   updated_at: string,
     * }|null
     */
    public function execute(string $refundId, string $merchantId): ?array
    {
        $refund = Refund::where('id', $refundId)
            ->where('merchant_id', $merchantId)
            ->first();

        if ($refund === null) {
            return null;
        }

        return [
            'refund_id' => $refund->id,
            'payment_id' => $refund->payment_id,
            'status' => $refund->status,
            'amount' => $refund->amount,
            'currency' => $refund->currency,
            'correlation_id' => $refund->correlation_id,
            'created_at' => $refund->created_at->toIso8601String(),
            'updated_at' => $refund->updated_at->toIso8601String(),
        ];
    }
}
