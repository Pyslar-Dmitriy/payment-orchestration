<?php

declare(strict_types=1);

namespace PaymentOrchestration\Contracts\Events;

use DateTimeImmutable;

/**
 * Transport wrapper for a domain event.
 * This is what gets serialised onto RabbitMQ / Kafka — it carries routing
 * metadata separately from the payload so consumers can route without
 * deserialising the full event body.
 */
final readonly class MessageEnvelope
{
    public function __construct(
        /** Unique ID for this message (used for inbox dedup). */
        public string $messageId,

        /** Fully-qualified event name, e.g. "payment.initiated.v1". */
        public string $eventName,

        /** Name of the service that published this message. */
        public string $source,

        /** Correlation ID propagated from the originating request. */
        public string $correlationId,

        /** Causation ID — the event or command that triggered this message. */
        public string $causationId,

        /** ISO 8601 timestamp of when the domain event occurred. */
        public string $occurredAt,

        /** Serialised event payload. */
        public array $payload,
    ) {
    }

    public static function fromDomainEvent(DomainEventInterface $event, string $source): self
    {
        return new self(
            messageId:     $event->eventId(),
            eventName:     $event->eventName(),
            source:        $source,
            correlationId: $event->correlationId()->toString(),
            causationId:   $event->causationId()->toString(),
            occurredAt:    $event->occurredAt()->format(DateTimeImmutable::ATOM),
            payload:       $event->toArray(),
        );
    }

    public function toArray(): array
    {
        return [
            'message_id'     => $this->messageId,
            'event_name'     => $this->eventName,
            'source'         => $this->source,
            'correlation_id' => $this->correlationId,
            'causation_id'   => $this->causationId,
            'occurred_at'    => $this->occurredAt,
            'payload'        => $this->payload,
        ];
    }
}