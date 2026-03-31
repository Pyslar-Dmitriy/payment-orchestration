<?php

declare(strict_types=1);

namespace PaymentOrchestration\Contracts\Events;

use DateTimeImmutable;
use PaymentOrchestration\SharedPrimitives\Correlation\CausationId;
use PaymentOrchestration\SharedPrimitives\Correlation\CorrelationId;

/**
 * Every domain event published to the event bus must implement this interface.
 * Services consume events by type; the envelope carries routing metadata.
 */
interface DomainEventInterface
{
    /**
     * Unique identifier of this specific event occurrence.
     */
    public function eventId(): string;

    /**
     * Fully-qualified event name, e.g. "payment.initiated.v1".
     */
    public function eventName(): string;

    /**
     * Traces the originating request across all services.
     */
    public function correlationId(): CorrelationId;

    /**
     * Identifies the command or event that directly caused this event.
     */
    public function causationId(): CausationId;

    /**
     * When this event occurred in the domain (not when it was published).
     */
    public function occurredAt(): DateTimeImmutable;

    /**
     * Serialisable event payload. Must not contain sensitive values (tokens, raw card data).
     */
    public function toArray(): array;
}