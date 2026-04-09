<?php

namespace Tests\Unit\Domain\Payment;

use App\Domain\Payment\PaymentStatus;
use PHPUnit\Framework\TestCase;
use ValueError;

class PaymentStatusTest extends TestCase
{
    public function test_all_cases_have_correct_backing_values(): void
    {
        $this->assertSame('created', PaymentStatus::CREATED->value);
        $this->assertSame('pending_provider', PaymentStatus::PENDING_PROVIDER->value);
        $this->assertSame('requires_action', PaymentStatus::REQUIRES_ACTION->value);
        $this->assertSame('authorized', PaymentStatus::AUTHORIZED->value);
        $this->assertSame('captured', PaymentStatus::CAPTURED->value);
        $this->assertSame('refunding', PaymentStatus::REFUNDING->value);
        $this->assertSame('refunded', PaymentStatus::REFUNDED->value);
        $this->assertSame('failed', PaymentStatus::FAILED->value);
        $this->assertSame('cancelled', PaymentStatus::CANCELLED->value);
        $this->assertSame('requires_reconciliation', PaymentStatus::REQUIRES_RECONCILIATION->value);
    }

    public function test_from_returns_correct_case(): void
    {
        $this->assertSame(PaymentStatus::CREATED, PaymentStatus::from('created'));
        $this->assertSame(PaymentStatus::PENDING_PROVIDER, PaymentStatus::from('pending_provider'));
        $this->assertSame(PaymentStatus::CAPTURED, PaymentStatus::from('captured'));
        $this->assertSame(PaymentStatus::FAILED, PaymentStatus::from('failed'));
        $this->assertSame(PaymentStatus::REQUIRES_RECONCILIATION, PaymentStatus::from('requires_reconciliation'));
    }

    public function test_try_from_returns_null_for_unknown_value(): void
    {
        $this->assertNull(PaymentStatus::tryFrom('unknown_status'));
        $this->assertNull(PaymentStatus::tryFrom('initiated')); // old value — must not exist
        $this->assertNull(PaymentStatus::tryFrom('authorizing')); // old value — must not exist
    }

    public function test_from_throws_for_unknown_value(): void
    {
        $this->expectException(ValueError::class);
        PaymentStatus::from('unknown_status');
    }

    public function test_cases_count(): void
    {
        $this->assertCount(10, PaymentStatus::cases());
    }
}
