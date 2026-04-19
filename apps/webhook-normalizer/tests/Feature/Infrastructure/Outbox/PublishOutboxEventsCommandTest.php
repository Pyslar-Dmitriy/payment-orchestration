<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Outbox;

use App\Infrastructure\Outbox\Publisher\BrokerPublisherInterface;
use App\Infrastructure\Outbox\Publisher\BrokerPublishException;
use App\Infrastructure\Outbox\Publisher\BrokerTransientException;
use App\Infrastructure\Outbox\Publisher\FakeBroker\FakeBrokerPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublishOutboxEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeBrokerPublisher $fakePublisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePublisher = new FakeBrokerPublisher;
        $this->app->instance(BrokerPublisherInterface::class, $this->fakePublisher);
    }

    private function insertOutboxRow(
        ?string $id = null,
        ?string $providerEventId = null,
        ?string $correlationId = null,
        ?string $paymentId = null,
    ): string {
        $id = $id ?? (string) Str::uuid();
        $now = now();

        DB::table('outbox_events')->insert([
            'id' => $id,
            'aggregate_type' => 'normalized_webhook_event',
            'aggregate_id' => $providerEventId ?? 'evt_test_001',
            'event_type' => 'provider.webhook_signal_received.v1',
            'payload' => json_encode([
                'correlation_id' => $correlationId ?? (string) Str::uuid(),
                'occurred_at' => $now->toIso8601String(),
                'signal_id' => $id,
                'raw_event_id' => (string) Str::uuid(),
                'provider' => 'mock',
                'provider_event_id' => $providerEventId ?? 'evt_test_001',
                'signal_type' => 'payment_captured',
                'payment_id' => $paymentId ?? '00000000-0000-0000-0000-000000000001',
                'provider_reference' => 'mock-ref-001',
                'normalized_at' => $now->toIso8601String(),
            ]),
            'created_at' => $now,
        ]);

        return $id;
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_publishes_pending_outbox_event_to_kafka(): void
    {
        $id = $this->insertOutboxRow();

        $this->artisan('outbox:publish --once')->assertSuccessful();

        $this->fakePublisher->assertPublished(
            'provider.webhooks.normalized.v1',
            'provider.webhook_signal_received',
        );

        $this->assertNotNull(
            DB::table('outbox_events')->where('id', $id)->value('published_at')
        );
    }

    public function test_marks_row_as_published_after_success(): void
    {
        $id = $this->insertOutboxRow();

        $this->artisan('outbox:publish --once')->assertSuccessful();

        $row = DB::table('outbox_events')->where('id', $id)->first();
        $this->assertNotNull($row->published_at);
    }

    public function test_publishes_multiple_pending_events(): void
    {
        $this->insertOutboxRow();
        $this->insertOutboxRow();

        $this->artisan('outbox:publish --once')->assertSuccessful();

        $this->fakePublisher->assertPublishedCount(2);
    }

    // -----------------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------------

    public function test_skips_already_published_rows(): void
    {
        $id = $this->insertOutboxRow();
        DB::table('outbox_events')->where('id', $id)->update(['published_at' => now()]);

        $this->artisan('outbox:publish --once')->assertSuccessful();

        $this->fakePublisher->assertNothingPublished();
    }

    // -----------------------------------------------------------------------
    // Envelope shape
    // -----------------------------------------------------------------------

    public function test_published_envelope_contains_required_fields(): void
    {
        $correlationId = (string) Str::uuid();
        $this->insertOutboxRow(correlationId: $correlationId);

        $this->artisan('outbox:publish --once')->assertSuccessful();

        $published = $this->fakePublisher->getPublished();
        $this->assertCount(1, $published);

        $body = $published[0]['body'];

        $this->assertSame('1', $body['schema_version']);
        $this->assertSame('webhook-normalizer', $body['source_service']);
        $this->assertSame('provider.webhook_signal_received', $body['event_type']);
        $this->assertSame($correlationId, $body['correlation_id']);
        $this->assertArrayHasKey('message_id', $body);
        $this->assertArrayHasKey('occurred_at', $body);
        $this->assertArrayHasKey('payload', $body);
    }

    public function test_published_payload_contains_signal_fields(): void
    {
        $paymentId = '00000000-0000-0000-0000-000000000042';
        $this->insertOutboxRow(paymentId: $paymentId);

        $this->artisan('outbox:publish --once')->assertSuccessful();

        $published = $this->fakePublisher->getPublished();
        $payload = $published[0]['body']['payload'];

        $this->assertSame('payment_captured', $payload['signal_type']);
        $this->assertSame($paymentId, $payload['payment_id']);
        $this->assertSame('mock', $payload['provider']);
    }

    // -----------------------------------------------------------------------
    // Retry on transient failure
    // -----------------------------------------------------------------------

    public function test_increments_retry_count_on_transient_failure(): void
    {
        $id = $this->insertOutboxRow();

        $failingPublisher = \Mockery::mock(BrokerPublisherInterface::class);
        $failingPublisher->shouldReceive('publish')
            ->once()
            ->andThrow(new BrokerTransientException('timeout'));

        $this->app->instance(BrokerPublisherInterface::class, $failingPublisher);

        $this->artisan('outbox:publish --once')->assertSuccessful();

        $row = DB::table('outbox_events')->where('id', $id)->first();
        $this->assertSame(1, (int) $row->retry_count);
        $this->assertNull($row->published_at);
        $this->assertSame('timeout', $row->last_error);
    }

    public function test_marks_permanently_failed_after_max_retries(): void
    {
        $id = $this->insertOutboxRow();
        DB::table('outbox_events')->where('id', $id)->update(['retry_count' => 4]);

        $failingPublisher = \Mockery::mock(BrokerPublisherInterface::class);
        $failingPublisher->shouldReceive('publish')
            ->once()
            ->andThrow(new BrokerTransientException('timeout'));

        $this->app->instance(BrokerPublisherInterface::class, $failingPublisher);

        $this->artisan('outbox:publish --once')->assertSuccessful();

        $row = DB::table('outbox_events')->where('id', $id)->first();
        $this->assertTrue((bool) $row->failed_permanently);
    }

    public function test_skips_permanently_failed_rows(): void
    {
        $id = $this->insertOutboxRow();
        DB::table('outbox_events')->where('id', $id)->update(['failed_permanently' => true]);

        $this->artisan('outbox:publish --once')->assertSuccessful();

        $this->fakePublisher->assertNothingPublished();
    }

    // -----------------------------------------------------------------------
    // Permanent broker failure (BrokerPublishException)
    // -----------------------------------------------------------------------

    public function test_marks_permanently_failed_immediately_on_broker_publish_exception(): void
    {
        $id = $this->insertOutboxRow();

        $failingPublisher = \Mockery::mock(BrokerPublisherInterface::class);
        $failingPublisher->shouldReceive('publish')
            ->once()
            ->andThrow(new BrokerPublishException('unsupported API version'));

        $this->app->instance(BrokerPublisherInterface::class, $failingPublisher);

        $this->artisan('outbox:publish --once')->assertSuccessful();

        $row = DB::table('outbox_events')->where('id', $id)->first();
        $this->assertTrue((bool) $row->failed_permanently);
        $this->assertNull($row->published_at);
        $this->assertSame('unsupported API version', $row->last_error);
        $this->assertSame(0, (int) $row->retry_count);
    }
}
