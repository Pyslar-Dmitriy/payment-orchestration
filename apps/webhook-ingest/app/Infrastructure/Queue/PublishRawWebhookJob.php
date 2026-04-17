<?php

namespace App\Infrastructure\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PublishRawWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $rawWebhookId) {}

    public function handle(): void
    {
        // TASK-083: Replace with AMQP publish to the provider.webhook.raw RabbitMQ queue.
        Log::info('Raw webhook enqueued for normalizer', ['raw_webhook_id' => $this->rawWebhookId]);
    }
}
