<?php

declare(strict_types=1);

namespace PaymentOrchestration\SharedPrimitives\Correlation;

use PaymentOrchestration\SharedPrimitives\Identity\Uuid;

/**
 * Typed causation ID — identifies which command or event caused this event.
 */
final readonly class CausationId
{
    private function __construct(private string $value)
    {
        Uuid::assertValid($value);
    }

    public static function generate(): self
    {
        return new self(Uuid::generate());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(CausationId $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}