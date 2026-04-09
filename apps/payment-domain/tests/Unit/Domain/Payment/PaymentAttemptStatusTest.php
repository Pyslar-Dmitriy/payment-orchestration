<?php

namespace Tests\Unit\Domain\Payment;

use App\Domain\Payment\PaymentAttemptStatus;
use PHPUnit\Framework\TestCase;

class PaymentAttemptStatusTest extends TestCase
{
    public function test_all_cases_have_correct_backing_values(): void
    {
        $this->assertSame('pending', PaymentAttemptStatus::PENDING->value);
        $this->assertSame('succeeded', PaymentAttemptStatus::SUCCEEDED->value);
        $this->assertSame('failed', PaymentAttemptStatus::FAILED->value);
    }

    public function test_from_returns_correct_case(): void
    {
        $this->assertSame(PaymentAttemptStatus::PENDING, PaymentAttemptStatus::from('pending'));
        $this->assertSame(PaymentAttemptStatus::SUCCEEDED, PaymentAttemptStatus::from('succeeded'));
    }

    public function test_try_from_returns_null_for_unknown_value(): void
    {
        $this->assertNull(PaymentAttemptStatus::tryFrom('invalid'));
    }

    public function test_cases_count(): void
    {
        $this->assertCount(3, PaymentAttemptStatus::cases());
    }
}
