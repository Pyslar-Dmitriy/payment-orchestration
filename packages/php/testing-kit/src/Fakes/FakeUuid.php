<?php

declare(strict_types=1);

namespace PaymentOrchestration\TestingKit\Fakes;

/**
 * Returns deterministic, sequential UUIDs for use in tests.
 * Call reset() between test cases to start the sequence from 1 again.
 */
final class FakeUuid
{
    private static int $counter = 0;

    private function __construct()
    {
    }

    public static function generate(): string
    {
        self::$counter++;

        return sprintf('00000000-0000-0000-0000-%012d', self::$counter);
    }

    public static function fixed(int $n): string
    {
        return sprintf('00000000-0000-0000-0000-%012d', $n);
    }

    public static function reset(): void
    {
        self::$counter = 0;
    }
}