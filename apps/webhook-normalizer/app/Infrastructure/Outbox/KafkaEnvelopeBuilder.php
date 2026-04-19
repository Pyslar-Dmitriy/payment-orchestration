<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use Illuminate\Support\Str;

// message_id equals the outbox row UUID — consumers use it for inbox deduplication.
final class KafkaEnvelopeBuilder
{
    public function build(OutboxEvent $event): array
    {
        $payload = $event->payload;
        $correlationId = (string) ($payload['correlation_id'] ?? '');

        return [
            'schema_version' => '1',
            'message_id' => $event->id,
            'correlation_id' => $correlationId !== '' ? $correlationId : (string) Str::uuid(),
            'causation_id' => $payload['causation_id'] ?? null,
            'source_service' => 'webhook-normalizer',
            'occurred_at' => $payload['occurred_at'] ?? now()->toIso8601String(),
            'event_type' => (string) preg_replace('/\.v\d+$/', '', $event->event_type),
            'payload' => $payload,
        ];
    }
}
