<?php

namespace App\Infrastructure\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PublishRawWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $rawWebhookId) {}

    public function handle(RabbitMqPublisherContract $publisher): void
    {
        $record = DB::table('webhook_events_raw')
            ->select(['id', 'provider', 'event_id', 'correlation_id'])
            ->where('id', $this->rawWebhookId)
            ->first();

        if ($record === null) {
            Log::warning('Raw webhook record not found — skipping publish', [
                'raw_webhook_id' => $this->rawWebhookId,
            ]);

            return;
        }

        $body = (string) json_encode([
            'raw_event_id' => $record->id,
            'provider' => $record->provider,
            'event_id' => $record->event_id,
            'correlation_id' => $record->correlation_id,
        ]);

        $publisher->publish('provider.webhook.raw', $this->rawWebhookId, $body);

        Log::info('Raw webhook published to normalizer queue', [
            'raw_webhook_id' => $this->rawWebhookId,
            'provider' => $record->provider,
            'event_id' => $record->event_id,
        ]);
    }
}
