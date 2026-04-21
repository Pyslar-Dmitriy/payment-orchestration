<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox\Publisher\Kafka;

use App\Infrastructure\Outbox\OutboxMessage;

final class KafkaEnvelopeBuilder
{
    /**
     * Build the Kafka wire-format envelope from an outbox message.
     *
     * `message_id` equals the outbox row UUID so consumers can use it for
     * inbox deduplication — the same ID is always published for a given row.
     *
     * @return array<string, mixed>
     */
    public function build(OutboxMessage $message): array
    {
        $payload = $message->payload;

        return [
            'schema_version' => '1',
            'message_id' => $message->id,
            'correlation_id' => $payload['correlation_id'] ?? '',
            'causation_id' => $payload['causation_id'] ?? null,
            'source_service' => 'ledger-service',
            'occurred_at' => $payload['occurred_at'] ?? now()->toIso8601String(),
            'event_type' => (string) preg_replace('/\.v\d+$/', '', $message->event_type),
            'payload' => $payload,
        ];
    }
}
