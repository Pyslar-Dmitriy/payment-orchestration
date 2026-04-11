<?php

namespace App\Infrastructure\Outbox\Publisher\Kafka;

use App\Infrastructure\Outbox\OutboxEvent;

final class KafkaEnvelopeBuilder
{
    /**
     * Build the Kafka wire-format envelope from an outbox event.
     *
     * The envelope shape matches the KafkaEnvelope contract (TASK-031).
     * `message_id` equals the outbox row UUID so consumers can use it for
     * inbox deduplication — the same ID is always published for a given row,
     * making retries safe.
     *
     * @return array<string, mixed>
     */
    public function build(OutboxEvent $event): array
    {
        $payload = $event->payload;

        return [
            'schema_version' => '1',
            'message_id' => $event->id,
            'correlation_id' => $payload['correlation_id'] ?? '',
            'causation_id' => $payload['causation_id'] ?? null,
            'source_service' => 'payment-domain',
            'occurred_at' => $payload['occurred_at'] ?? now()->toIso8601String(),
            // Strip the .vN version suffix from the internal event type; versioning
            // is carried by the topic name (payments.lifecycle.v1), not the field.
            'event_type' => (string) preg_replace('/\.v\d+$/', '', $event->event_type),
            'payload' => $payload,
        ];
    }
}
