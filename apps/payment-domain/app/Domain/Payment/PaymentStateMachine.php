<?php

namespace App\Domain\Payment;

/**
 * Centralised payment state machine.
 *
 * The transition table is the canonical source of truth for which status
 * changes are allowed. All other code must use this class — no hard-coded
 * allowed/forbidden checks elsewhere.
 *
 * See: docs/tasks/epic-05/TASK-056.md
 */
final class PaymentStateMachine
{
    /**
     * Returns the allowed transition map.
     * Key = current status value (string); value = array of allowed target PaymentStatus cases.
     * Statuses absent from the map (failed, cancelled, refunded) are terminal — no transitions out.
     *
     * @return array<string, list<PaymentStatus>>
     */
    private static function transitions(): array
    {
        return [
            PaymentStatus::CREATED->value => [PaymentStatus::PENDING_PROVIDER],
            PaymentStatus::PENDING_PROVIDER->value => [PaymentStatus::AUTHORIZED, PaymentStatus::CAPTURED, PaymentStatus::REQUIRES_ACTION, PaymentStatus::FAILED],
            PaymentStatus::REQUIRES_ACTION->value => [PaymentStatus::AUTHORIZED, PaymentStatus::CAPTURED, PaymentStatus::FAILED],
            PaymentStatus::AUTHORIZED->value => [PaymentStatus::CAPTURED, PaymentStatus::CANCELLED, PaymentStatus::FAILED, PaymentStatus::REQUIRES_RECONCILIATION],
            PaymentStatus::CAPTURED->value => [PaymentStatus::REFUNDING, PaymentStatus::REQUIRES_RECONCILIATION],
            PaymentStatus::REFUNDING->value => [PaymentStatus::REFUNDED, PaymentStatus::CAPTURED, PaymentStatus::REQUIRES_RECONCILIATION],
            PaymentStatus::REQUIRES_RECONCILIATION->value => [PaymentStatus::CAPTURED, PaymentStatus::REFUNDED],
            PaymentStatus::FAILED->value => [],
            PaymentStatus::CANCELLED->value => [],
            PaymentStatus::REFUNDED->value => [],
        ];
    }

    public static function isAllowed(PaymentStatus $from, PaymentStatus $to): bool
    {
        return in_array($to, self::transitions()[$from->value] ?? [], strict: true);
    }
}
