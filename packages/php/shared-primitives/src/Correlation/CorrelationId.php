<?php

declare(strict_types=1);

namespace PaymentOrchestration\SharedPrimitives\Correlation;

use PaymentOrchestration\SharedPrimitives\Identity\Uuid;

/**
 * Typed correlation ID — traces a request across all services and messages.
 */
final readonly class CorrelationId
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

    public function equals(CorrelationId $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}