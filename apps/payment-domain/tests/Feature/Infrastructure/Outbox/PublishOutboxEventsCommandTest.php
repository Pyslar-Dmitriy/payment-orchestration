<?php

namespace Tests\Feature\Infrastructure\Outbox;

use App\Infrastructure\Outbox\OutboxEvent;
use App\Infrastructure\Outbox\Publisher\BrokerTransientException;
use App\Infrastructure\Outbox\Publisher\FakeBroker\FakeBrokerPublisher;
use App\Infrastructure\Outbox\Publisher\Kafka\KafkaBrokerPublisher;
use App\Infrastructure\Outbox\Publisher\RabbitMq\RabbitMqBrokerPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishOutboxEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeBrokerPublisher $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeBrokerPublisher;
        $this->app->instance(KafkaBrokerPublisher::class, $this->fake);
        $this->app->instance(RabbitMqBrokerPublisher::class, $this->fake);
        $this->app->instance(FakeBrokerPublisher::class, $this->fake);
    }

    private function makeOutboxEvent(array $overrides = []): OutboxEvent
    {
        return OutboxEvent::create(array_merge([
            'aggregate_type' => 'Payment',
            'aggregate_id' => 'pay-00000000-0000-0000-0000-000000000001',
            'event_type' => 'payment.initiated.v1',
            'payload' => [
                'payment_id' => 'pay-00000000-0000-0000-0000-000000000001',
                'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
                'occurred_at' => '2026-04-10T12:00:00+00:00',
            ],
        ], $overrides));
    }

    public function test_publishes_pending_event_and_marks_as_published(): void
    {
        $event = $this->makeOutboxEvent();

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $updated = OutboxEvent::find($event->id);
        $this->assertNotNull($updated->published_at);
        $this->assertSame(0, $updated->retry_count);
        $this->assertFalse($updated->failed_permanently);
    }

    public function test_publishes_to_correct_kafka_topic_for_payment_event(): void
    {
        $this->makeOutboxEvent(['event_type' => 'payment.initiated.v1']);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->assertPublished('payments.lifecycle.v1', 'payment.initiated');
    }

    public function test_publishes_to_correct_kafka_topic_for_refund_event(): void
    {
        $this->makeOutboxEvent([
            'aggregate_type' => 'Refund',
            'event_type' => 'refund.initiated.v1',
            'payload' => [
                'refund_id' => 'ref-001',
                'correlation_id' => 'corr-001',
                'occurred_at' => '2026-04-10T12:00:00+00:00',
            ],
        ]);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->assertPublished('refunds.lifecycle.v1', 'refund.initiated');
    }

    public function test_skips_already_published_events(): void
    {
        $this->makeOutboxEvent(['published_at' => now()]);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->assertNothingPublished();
    }

    public function test_skips_permanently_failed_events(): void
    {
        $this->makeOutboxEvent(['failed_permanently' => true]);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->assertNothingPublished();
    }

    public function test_increments_retry_count_on_transient_failure(): void
    {
        $event = $this->makeOutboxEvent();

        $throwingPublisher = new class implements KafkaBrokerPublisher
        {
            public function publish(string $destination, string $messageId, string $body, array $headers = []): void
            {
                throw new BrokerTransientException('connection timeout');
            }
        };

        $this->app->instance(KafkaBrokerPublisher::class, $throwingPublisher);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $updated = OutboxEvent::find($event->id);
        $this->assertNull($updated->published_at);
        $this->assertSame(1, $updated->retry_count);
        $this->assertFalse($updated->failed_permanently);
        $this->assertStringContainsString('connection timeout', $updated->last_error);
    }

    public function test_marks_permanently_failed_after_max_retries(): void
    {
        $maxRetries = (int) config('outbox.max_retries', 5);

        // Pre-set retry count to one below the limit.
        $event = $this->makeOutboxEvent(['retry_count' => $maxRetries - 1]);

        $throwingPublisher = new class implements KafkaBrokerPublisher
        {
            public function publish(string $destination, string $messageId, string $body, array $headers = []): void
            {
                throw new BrokerTransientException('broker unavailable');
            }
        };

        $this->app->instance(KafkaBrokerPublisher::class, $throwingPublisher);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $updated = OutboxEvent::find($event->id);
        $this->assertNull($updated->published_at);
        $this->assertSame($maxRetries, $updated->retry_count);
        $this->assertTrue($updated->failed_permanently);
    }

    public function test_dead_letters_unroutable_event_immediately(): void
    {
        $event = $this->makeOutboxEvent(['event_type' => 'unknown.event.v1']);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $updated = OutboxEvent::find($event->id);
        $this->assertNull($updated->published_at);
        $this->assertSame(0, $updated->retry_count);
        $this->assertTrue($updated->failed_permanently);
        $this->assertStringContainsString('unknown.event.v1', $updated->last_error);
    }

    public function test_envelope_contains_schema_version_and_message_id(): void
    {
        $event = $this->makeOutboxEvent();

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $published = $this->fake->getPublished();

        $this->assertCount(1, $published);
        $this->assertSame('1', $published[0]['body']['schema_version']);
        $this->assertSame($event->id, $published[0]['body']['message_id']);
        $this->assertSame('payment-domain', $published[0]['body']['source_service']);
    }

    public function test_multiple_pending_events_are_all_published(): void
    {
        $this->makeOutboxEvent(['aggregate_id' => 'pay-001']);
        $this->makeOutboxEvent(['aggregate_id' => 'pay-002']);
        $this->makeOutboxEvent(['aggregate_id' => 'pay-003']);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->assertPublishedCount(3);

        $this->assertSame(
            0,
            OutboxEvent::whereNull('published_at')->where('failed_permanently', false)->count()
        );
    }

    public function test_published_event_is_not_re_published_on_second_run(): void
    {
        $this->makeOutboxEvent();

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->reset();

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->assertNothingPublished();
    }
}
