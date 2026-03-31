<?php

declare(strict_types=1);

namespace PaymentOrchestration\TestingKit\Fakes;

use PaymentOrchestration\SharedPrimitives\Correlation\CausationId;
use PaymentOrchestration\SharedPrimitives\Correlation\CorrelationId;

/**
 * Provides fixed correlation and causation IDs for deterministic test assertions.
 *
 * Usage:
 *   $ctx = FakeCorrelationContext::default();
 *   $ctx->correlationId() // always the same UUID
 */
final readonly class FakeCorrelationContext
{
    private function __construct(
        private CorrelationId $correlationId,
        private CausationId $causationId,
    ) {
    }

    public static function default(): self
    {
        return new self(
            CorrelationId::fromString('00000000-0000-0000-0000-000000000001'),
            CausationId::fromString('00000000-0000-0000-0000-000000000002'),
        );
    }

    public static function fromStrings(string $correlationId, string $causationId): self
    {
        return new self(
            CorrelationId::fromString($correlationId),
            CausationId::fromString($causationId),
        );
    }

    public function correlationId(): CorrelationId
    {
        return $this->correlationId;
    }

    public function causationId(): CausationId
    {
        return $this->causationId;
    }
}