<?php

namespace Tests\Unit\Domain\Refund;

use App\Domain\Refund\RefundStatus;
use PHPUnit\Framework\TestCase;

class RefundStatusTest extends TestCase
{
    public function test_all_cases_have_correct_backing_values(): void
    {
        $this->assertSame('pending', RefundStatus::PENDING->value);
        $this->assertSame('succeeded', RefundStatus::SUCCEEDED->value);
        $this->assertSame('failed', RefundStatus::FAILED->value);
    }

    public function test_from_returns_correct_case(): void
    {
        $this->assertSame(RefundStatus::PENDING, RefundStatus::from('pending'));
        $this->assertSame(RefundStatus::SUCCEEDED, RefundStatus::from('succeeded'));
    }

    public function test_try_from_returns_null_for_unknown_value(): void
    {
        $this->assertNull(RefundStatus::tryFrom('invalid'));
    }

    public function test_cases_count(): void
    {
        $this->assertCount(3, RefundStatus::cases());
    }
}
