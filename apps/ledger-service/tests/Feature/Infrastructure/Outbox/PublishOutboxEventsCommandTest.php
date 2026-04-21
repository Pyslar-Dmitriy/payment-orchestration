<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Outbox;

use App\Infrastructure\Outbox\OutboxMessage;
use App\Infrastructure\Outbox\Publisher\BrokerPublisherInterface;
use App\Infrastructure\Outbox\Publisher\BrokerPublishException;
use App\Infrastructure\Outbox\Publisher\BrokerTransientException;
use App\Infrastructure\Outbox\Publisher\FakeBroker\FakeBrokerPublisher;
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
        $this->app->instance(BrokerPublisherInterface::class, $this->fake);
    }

    private function makeOutboxMessage(array $overrides = []): OutboxMessage
    {
        return OutboxMessage::create(array_merge([
            'aggregate_type' => 'LedgerTransaction',
            'aggregate_id' => 'txn-00000000-0000-0000-0000-000000000001',
            'event_type' => 'ledger.entry_posted.v1',
            'payload' => [
                'entry_id' => 'txn-00000000-0000-0000-0000-000000000001',
                'payment_id' => '00000000-0000-0000-0000-000000000001',
                'merchant_id' => 'merchant-abc',
                'posting_type' => 'capture',
                'lines' => [
                    ['account' => 'escrow.platform', 'direction' => 'debit', 'amount' => ['value' => 10000, 'currency' => 'USD']],
                    ['account' => 'merchant.merchant-abc', 'direction' => 'credit', 'amount' => ['value' => 10000, 'currency' => 'USD']],
                ],
                'idempotency_key' => 'capture:00000000-0000-0000-0000-000000000001',
                'posted_at' => '2026-04-21T12:00:00+00:00',
                'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
                'causation_id' => null,
                'occurred_at' => '2026-04-21T12:00:00+00:00',
            ],
        ], $overrides));
    }

    public function test_publishes_pending_message_and_marks_as_published(): void
    {
        $message = $this->makeOutboxMessage();

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $updated = OutboxMessage::find($message->id);
        $this->assertNotNull($updated->published_at);
        $this->assertSame(0, $updated->retry_count);
        $this->assertFalse($updated->failed_permanently);
    }

    public function test_publishes_to_correct_kafka_topic_for_ledger_event(): void
    {
        $this->makeOutboxMessage();

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->assertPublished('ledger.entries.v1', 'ledger.entry_posted');
    }

    public function test_skips_already_published_messages(): void
    {
        $this->makeOutboxMessage(['published_at' => now()]);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->assertNothingPublished();
    }

    public function test_skips_permanently_failed_messages(): void
    {
        $this->makeOutboxMessage(['failed_permanently' => true]);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->assertNothingPublished();
    }

    public function test_increments_retry_count_on_transient_failure(): void
    {
        $message = $this->makeOutboxMessage();

        $throwingPublisher = new class implements BrokerPublisherInterface
        {
            public function publish(string $destination, string $messageId, string $body, array $headers = []): void
            {
                throw new BrokerTransientException('connection timeout');
            }
        };

        $this->app->instance(BrokerPublisherInterface::class, $throwingPublisher);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $updated = OutboxMessage::find($message->id);
        $this->assertNull($updated->published_at);
        $this->assertSame(1, $updated->retry_count);
        $this->assertFalse($updated->failed_permanently);
        $this->assertStringContainsString('connection timeout', $updated->last_error);
    }

    public function test_marks_permanently_failed_after_max_retries(): void
    {
        $maxRetries = (int) config('outbox.max_retries', 5);

        $message = $this->makeOutboxMessage(['retry_count' => $maxRetries - 1]);

        $throwingPublisher = new class implements BrokerPublisherInterface
        {
            public function publish(string $destination, string $messageId, string $body, array $headers = []): void
            {
                throw new BrokerTransientException('broker unavailable');
            }
        };

        $this->app->instance(BrokerPublisherInterface::class, $throwingPublisher);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $updated = OutboxMessage::find($message->id);
        $this->assertNull($updated->published_at);
        $this->assertSame($maxRetries, $updated->retry_count);
        $this->assertTrue($updated->failed_permanently);
    }

    public function test_dead_letters_unroutable_event_immediately(): void
    {
        $message = $this->makeOutboxMessage(['event_type' => 'unknown.event.v1']);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $updated = OutboxMessage::find($message->id);
        $this->assertNull($updated->published_at);
        $this->assertSame(0, $updated->retry_count);
        $this->assertTrue($updated->failed_permanently);
        $this->assertStringContainsString('unknown.event.v1', $updated->last_error);
    }

    public function test_envelope_contains_schema_version_and_message_id(): void
    {
        $message = $this->makeOutboxMessage();

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $published = $this->fake->getPublished();

        $this->assertCount(1, $published);
        $this->assertSame('1', $published[0]['body']['schema_version']);
        $this->assertSame($message->id, $published[0]['body']['message_id']);
        $this->assertSame('ledger-service', $published[0]['body']['source_service']);
    }

    public function test_multiple_pending_messages_are_all_published(): void
    {
        $this->makeOutboxMessage(['aggregate_id' => 'txn-001']);
        $this->makeOutboxMessage(['aggregate_id' => 'txn-002']);
        $this->makeOutboxMessage(['aggregate_id' => 'txn-003']);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->assertPublishedCount(3);

        $this->assertSame(
            0,
            OutboxMessage::whereNull('published_at')->where('failed_permanently', false)->count()
        );
    }

    public function test_published_message_is_not_re_published_on_second_run(): void
    {
        $this->makeOutboxMessage();

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->reset();

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $this->fake->assertNothingPublished();
    }

    public function test_dead_letters_message_on_permanent_broker_failure(): void
    {
        $message = $this->makeOutboxMessage();

        $throwingPublisher = new class implements BrokerPublisherInterface
        {
            public function publish(string $destination, string $messageId, string $body, array $headers = []): void
            {
                throw new BrokerPublishException('schema rejected by registry');
            }
        };

        $this->app->instance(BrokerPublisherInterface::class, $throwingPublisher);

        $this->artisan('outbox:publish --once')->assertExitCode(0);

        $updated = OutboxMessage::find($message->id);
        $this->assertNull($updated->published_at);
        $this->assertSame(0, $updated->retry_count);
        $this->assertTrue($updated->failed_permanently);
        $this->assertStringContainsString('schema rejected by registry', $updated->last_error);
    }
}
