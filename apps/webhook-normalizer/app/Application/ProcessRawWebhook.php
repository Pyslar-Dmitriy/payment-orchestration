<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProcessRawWebhook
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(string $messageId, array $payload): void
    {
        $alreadyProcessed = DB::table('inbox_messages')
            ->where('message_id', $messageId)
            ->exists();

        if ($alreadyProcessed) {
            Log::info('Duplicate raw webhook message — skipping', [
                'message_id' => $messageId,
            ]);

            return;
        }

        $rawEventId = (string) ($payload['raw_event_id'] ?? '');
        $provider = (string) ($payload['provider'] ?? '');
        $eventId = (string) ($payload['event_id'] ?? '');
        $correlationId = (string) ($payload['correlation_id'] ?? '');

        // TASK-091: load raw payload from webhook-ingest and map to internal event type
        // TASK-092: signal Temporal workflow with the normalized event
        // TASK-093: publish normalized Kafka event

        $now = now();

        DB::table('inbox_messages')->insert([
            'message_id' => $messageId,
            'processed_at' => $now,
            'created_at' => $now,
        ]);

        Log::info('Raw webhook received by normalizer', [
            'message_id' => $messageId,
            'raw_event_id' => $rawEventId,
            'provider' => $provider,
            'event_id' => $eventId,
            'correlation_id' => $correlationId,
        ]);
    }
}
