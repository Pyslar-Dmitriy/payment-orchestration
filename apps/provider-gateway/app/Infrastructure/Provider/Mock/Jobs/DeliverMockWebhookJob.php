<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Mock\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a mock webhook payload to the configured ingest URL.
 *
 * Used by MockProviderAdapter for async/delayed/duplicate/out-of-order scenarios.
 * If MOCK_PROVIDER_WEBHOOK_URL is not configured, the job is a no-op — this lets
 * unit tests run without a real webhook endpoint.
 */
final class DeliverMockWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $eventId,
        public readonly string $paymentReference,
        public readonly string $eventType,
        public readonly string $status,
        public readonly ?string $webhookUrl,
    ) {}

    public function handle(): void
    {
        if ($this->webhookUrl === null || $this->webhookUrl === '') {
            Log::debug('MockProvider: webhook_url not configured, skipping webhook delivery', [
                'event_id' => $this->eventId,
                'payment_reference' => $this->paymentReference,
            ]);

            return;
        }

        Log::info('MockProvider: delivering mock webhook', [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'payment_reference' => $this->paymentReference,
            'status' => $this->status,
            'webhook_url' => $this->webhookUrl,
        ]);

        Http::post($this->webhookUrl, [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'payment_reference' => $this->paymentReference,
            'status' => $this->status,
            'provider' => 'mock',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
