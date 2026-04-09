<?php

namespace Tests\Unit\Domain\Payment;

use App\Domain\Payment\PaymentStateMachine;
use App\Domain\Payment\PaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentStateMachineTest extends TestCase
{
    #[DataProvider('validTransitionProvider')]
    public function test_valid_transition_is_allowed(PaymentStatus $from, PaymentStatus $to): void
    {
        $this->assertTrue(PaymentStateMachine::isAllowed($from, $to));
    }

    public static function validTransitionProvider(): array
    {
        return [
            'created → pending_provider' => [PaymentStatus::CREATED, PaymentStatus::PENDING_PROVIDER],
            'pending_provider → authorized' => [PaymentStatus::PENDING_PROVIDER, PaymentStatus::AUTHORIZED],
            'pending_provider → captured (direct)' => [PaymentStatus::PENDING_PROVIDER, PaymentStatus::CAPTURED],
            'pending_provider → requires_action' => [PaymentStatus::PENDING_PROVIDER, PaymentStatus::REQUIRES_ACTION],
            'pending_provider → failed' => [PaymentStatus::PENDING_PROVIDER, PaymentStatus::FAILED],
            'requires_action → authorized' => [PaymentStatus::REQUIRES_ACTION, PaymentStatus::AUTHORIZED],
            'requires_action → captured' => [PaymentStatus::REQUIRES_ACTION, PaymentStatus::CAPTURED],
            'requires_action → failed' => [PaymentStatus::REQUIRES_ACTION, PaymentStatus::FAILED],
            'authorized → captured' => [PaymentStatus::AUTHORIZED, PaymentStatus::CAPTURED],
            'authorized → cancelled' => [PaymentStatus::AUTHORIZED, PaymentStatus::CANCELLED],
            'authorized → failed' => [PaymentStatus::AUTHORIZED, PaymentStatus::FAILED],
            'captured → refunding' => [PaymentStatus::CAPTURED, PaymentStatus::REFUNDING],
            'captured → requires_reconciliation' => [PaymentStatus::CAPTURED, PaymentStatus::REQUIRES_RECONCILIATION],
            'refunding → refunded' => [PaymentStatus::REFUNDING, PaymentStatus::REFUNDED],
            'refunding → captured (refund rejected)' => [PaymentStatus::REFUNDING, PaymentStatus::CAPTURED],
            'refunding → requires_reconciliation' => [PaymentStatus::REFUNDING, PaymentStatus::REQUIRES_RECONCILIATION],
            'requires_reconciliation → captured' => [PaymentStatus::REQUIRES_RECONCILIATION, PaymentStatus::CAPTURED],
            'requires_reconciliation → refunded' => [PaymentStatus::REQUIRES_RECONCILIATION, PaymentStatus::REFUNDED],
        ];
    }

    #[DataProvider('forbiddenTransitionProvider')]
    public function test_forbidden_transition_is_rejected(PaymentStatus $from, PaymentStatus $to): void
    {
        $this->assertFalse(PaymentStateMachine::isAllowed($from, $to));
    }

    public static function forbiddenTransitionProvider(): array
    {
        return [
            'created → captured (skipping steps)' => [PaymentStatus::CREATED, PaymentStatus::CAPTURED],
            'created → authorized' => [PaymentStatus::CREATED, PaymentStatus::AUTHORIZED],
            'created → created (self-loop)' => [PaymentStatus::CREATED, PaymentStatus::CREATED],
            'pending_provider → refunding' => [PaymentStatus::PENDING_PROVIDER, PaymentStatus::REFUNDING],
            'authorized → created (backward)' => [PaymentStatus::AUTHORIZED, PaymentStatus::CREATED],
            'authorized → pending_provider' => [PaymentStatus::AUTHORIZED, PaymentStatus::PENDING_PROVIDER],
            'captured → failed' => [PaymentStatus::CAPTURED, PaymentStatus::FAILED],
            'captured → cancelled' => [PaymentStatus::CAPTURED, PaymentStatus::CANCELLED],
            'captured → authorized (backward)' => [PaymentStatus::CAPTURED, PaymentStatus::AUTHORIZED],
            'failed → pending_provider (from terminal)' => [PaymentStatus::FAILED, PaymentStatus::PENDING_PROVIDER],
            'failed → created (from terminal)' => [PaymentStatus::FAILED, PaymentStatus::CREATED],
            'cancelled → authorized (from terminal)' => [PaymentStatus::CANCELLED, PaymentStatus::AUTHORIZED],
            'refunded → refunding (from terminal)' => [PaymentStatus::REFUNDED, PaymentStatus::REFUNDING],
            'refunded → captured (from terminal)' => [PaymentStatus::REFUNDED, PaymentStatus::CAPTURED],
        ];
    }
}
