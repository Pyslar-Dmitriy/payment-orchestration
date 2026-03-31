<?php

declare(strict_types=1);

namespace PaymentOrchestration\SharedPrimitives\Money;

use InvalidArgumentException;

/**
 * Immutable Money value object.
 * Amount is stored in minor units (e.g. cents) as an integer to avoid float precision issues.
 */
final readonly class Money
{
    private function __construct(
        private int $amount,
        private string $currency,
    ) {
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException("Currency must be a 3-character ISO 4217 code, got: '{$currency}'");
        }
    }

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, strtoupper($currency));
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function equals(Money $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function isGreaterThan(Money $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function isLessThan(Money $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function toArray(): array
    {
        return [
            'amount'   => $this->amount,
            'currency' => $this->currency,
        ];
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot operate on different currencies: {$this->currency} and {$other->currency}"
            );
        }
    }
}