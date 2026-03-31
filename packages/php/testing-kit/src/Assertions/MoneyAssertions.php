<?php

declare(strict_types=1);

namespace PaymentOrchestration\TestingKit\Assertions;

use PaymentOrchestration\SharedPrimitives\Money\Money;
use PHPUnit\Framework\Assert;

/**
 * PHPUnit assertion helpers for Money value objects.
 * Use as a trait inside your test cases.
 */
trait MoneyAssertions
{
    public function assertMoneyEquals(Money $expected, Money $actual, string $message = ''): void
    {
        Assert::assertTrue(
            $expected->equals($actual),
            $message ?: sprintf(
                'Failed asserting that Money(%d %s) equals Money(%d %s)',
                $actual->amount(),
                $actual->currency(),
                $expected->amount(),
                $expected->currency(),
            ),
        );
    }

    public function assertMoneyAmount(int $expectedAmount, Money $actual, string $message = ''): void
    {
        Assert::assertSame(
            $expectedAmount,
            $actual->amount(),
            $message ?: "Failed asserting Money amount is {$expectedAmount}, got {$actual->amount()}",
        );
    }

    public function assertMoneyCurrency(string $expectedCurrency, Money $actual, string $message = ''): void
    {
        Assert::assertSame(
            strtoupper($expectedCurrency),
            $actual->currency(),
            $message ?: "Failed asserting Money currency is {$expectedCurrency}, got {$actual->currency()}",
        );
    }

    public function assertMoneyIsZero(Money $actual, string $message = ''): void
    {
        Assert::assertTrue(
            $actual->isZero(),
            $message ?: "Failed asserting Money({$actual->amount()} {$actual->currency()}) is zero",
        );
    }

    public function assertMoneyIsNegative(Money $actual, string $message = ''): void
    {
        Assert::assertTrue(
            $actual->isNegative(),
            $message ?: "Failed asserting Money({$actual->amount()} {$actual->currency()}) is negative",
        );
    }
}