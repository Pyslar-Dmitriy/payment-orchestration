<?php

namespace App\Application;

use App\Domain\Normalizer\NormalizedWebhookEvent;
use App\Domain\Normalizer\ProviderNormalizerRegistry;
use App\Domain\Normalizer\UnmappableWebhookException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProcessRawWebhook
{
    public function __construct(private readonly ProviderNormalizerRegistry $normalizerRegistry) {}

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

        // TASK-092: fetch the raw provider body from webhook-ingest via internal HTTP,
        // then pass it to $this->normalizerRegistry->normalize($provider, $rawProviderBody).
        // For now, normalization is exercised via the registry once the body is available.
        $normalizedEvent = $this->tryNormalize($provider, $payload);

        // TASK-092: signal Temporal workflow with $normalizedEvent
        // TASK-093: publish normalized Kafka event with $normalizedEvent

        $now = now();

        DB::transaction(function () use ($messageId, $now): void {
            DB::table('inbox_messages')->insert([
                'message_id' => $messageId,
                'processed_at' => $now,
                'created_at' => $now,
            ]);
        });

        Log::info('Raw webhook received by normalizer', [
            'message_id' => $messageId,
            'raw_event_id' => $rawEventId,
            'provider' => $provider,
            'event_id' => $eventId,
            'correlation_id' => $correlationId,
            'internal_status' => $normalizedEvent?->internalStatus,
        ]);
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function tryNormalize(string $provider, array $rawPayload): ?NormalizedWebhookEvent
    {
        if ($provider === '') {
            return null;
        }

        try {
            return $this->normalizerRegistry->normalize($provider, $rawPayload);
        } catch (UnmappableWebhookException $e) {
            Log::warning('Could not normalize webhook payload', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
