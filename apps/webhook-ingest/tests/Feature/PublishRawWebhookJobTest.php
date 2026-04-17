<?php

namespace Tests\Feature;

use App\Infrastructure\Queue\PublishRawWebhookJob;
use App\Infrastructure\Queue\RabbitMqPublisherContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class PublishRawWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    private function insertRawWebhook(string $id, string $provider = 'mock', string $eventId = 'evt_001', ?string $correlationId = null): void
    {
        DB::table('webhook_events_raw')->insert([
            'id' => $id,
            'provider' => $provider,
            'event_id' => $eventId,
            'headers' => json_encode([]),
            'payload' => '{"type":"payment.completed"}',
            'signature_verified' => false,
            'correlation_id' => $correlationId ?? Str::uuid()->toString(),
            'processing_state' => 'enqueued',
            'received_at' => now(),
        ]);
    }

    private function runJob(PublishRawWebhookJob $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_publishes_message_to_provider_webhook_raw_queue(): void
    {
        $id = Str::uuid()->toString();
        $this->insertRawWebhook($id);

        $this->mock(RabbitMqPublisherContract::class, function (MockInterface $mock) use ($id): void {
            $mock->shouldReceive('publish')
                ->once()
                ->withArgs(function (string $queue, string $messageId, string $body) use ($id): bool {
                    return $queue === 'provider.webhook.raw'
                        && $messageId === $id
                        && json_decode($body, true)['raw_event_id'] === $id;
                });
        });

        $this->runJob(new PublishRawWebhookJob($id));
    }

    public function test_message_body_contains_expected_fields(): void
    {
        $id = Str::uuid()->toString();
        $correlationId = Str::uuid()->toString();
        $this->insertRawWebhook($id, 'stripe', 'evt_stripe_999', $correlationId);

        $capturedBody = null;

        $this->mock(RabbitMqPublisherContract::class, function (MockInterface $mock) use (&$capturedBody): void {
            $mock->shouldReceive('publish')
                ->once()
                ->withArgs(function (string $queue, string $messageId, string $body) use (&$capturedBody): bool {
                    $capturedBody = $body;

                    return true;
                });
        });

        $this->runJob(new PublishRawWebhookJob($id));

        $decoded = json_decode((string) $capturedBody, true);

        $this->assertSame($id, $decoded['raw_event_id']);
        $this->assertSame('stripe', $decoded['provider']);
        $this->assertSame('evt_stripe_999', $decoded['event_id']);
        $this->assertSame($correlationId, $decoded['correlation_id']);
    }

    public function test_message_id_equals_raw_webhook_id_for_idempotent_republishing(): void
    {
        $id = Str::uuid()->toString();
        $this->insertRawWebhook($id);

        $capturedMessageId = null;

        $this->mock(RabbitMqPublisherContract::class, function (MockInterface $mock) use (&$capturedMessageId): void {
            $mock->shouldReceive('publish')
                ->once()
                ->withArgs(function (string $queue, string $messageId, string $body) use (&$capturedMessageId): bool {
                    $capturedMessageId = $messageId;

                    return true;
                });
        });

        $this->runJob(new PublishRawWebhookJob($id));

        $this->assertSame($id, $capturedMessageId);
    }

    // -----------------------------------------------------------------------
    // Missing record — guard against lost/corrupted state
    // -----------------------------------------------------------------------

    public function test_skips_publish_when_record_not_found(): void
    {
        $nonExistentId = Str::uuid()->toString();

        $this->mock(RabbitMqPublisherContract::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('publish');
        });

        // Must complete without throwing
        $this->runJob(new PublishRawWebhookJob($nonExistentId));
    }
}
