<?php

namespace App\Domain\Refund;

final class RefundStateMachine
{
    /**
     * @return array<string, list<RefundStatus>>
     */
    private static function transitions(): array
    {
        return [
            RefundStatus::PENDING->value => [RefundStatus::PENDING_PROVIDER, RefundStatus::FAILED],
            RefundStatus::PENDING_PROVIDER->value => [RefundStatus::SUCCEEDED, RefundStatus::FAILED, RefundStatus::REQUIRES_RECONCILIATION],
        ];
    }

    public static function isAllowed(RefundStatus $from, RefundStatus $to): bool
    {
        return in_array($to, self::transitions()[$from->value] ?? [], strict: true);
    }
}
