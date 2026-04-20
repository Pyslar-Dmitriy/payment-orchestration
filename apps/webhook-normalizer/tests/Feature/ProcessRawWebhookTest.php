<?php

namespace Tests\Feature;

use App\Application\ProcessRawWebhook;
use App\Domain\Signal\DeadWorkflowException;
use App\Domain\Signal\TemporalSignalDispatcherInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ProcessRawWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT_UUID = '00000000-0000-0000-0000-000000000001';

    private TemporalSignalDispatcherInterface $signalDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signalDispatcher = Mockery::mock(TemporalSignalDispatcherInterface::class);
        $this->app->instance(TemporalSignalDispatcherInterface::class, $this->signalDispatcher);
    }

    private function validPayload(
        ?string $rawEventId = null,
        string $provider = 'mock',
        string $eventId = 'evt_001',
        ?string $correlationId = null,
        string $status = 'CAPTURED',
        string $eventType = 'payment.captured',
        string $paymentUuid = self::PAYMENT_UUID,
    ): array {
        return [
            'raw_event_id' => $rawEventId ?? Str::uuid()->toString(),
            'provider' => $provider,
            'event_id' => $eventId,
            'correlation_id' => $correlationId ?? Str::uuid()->toString(),
            'status' => $status,
            'event_type' => $eventType,
            'payment_reference' => 'mock-'.$paymentUuid,
        ];
    }

    private function processor(): ProcessRawWebhook
    {
        return $this->app->make(ProcessRawWebhook::class);
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_records_message_in_inbox_on_first_processing(): void
    {
        $messageId = Str::uuid()->toString();

        $this->signalDispatcher->shouldReceive('dispatch')->once();

        $this->processor()->execute($messageId, $this->validPayload());

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $messageId]);
    }

    public function test_inbox_entry_has_processed_at_and_created_at(): void
    {
        $messageId = Str::uuid()->toString();

        $this->signalDispatcher->shouldReceive('dispatch')->once();

        $this->processor()->execute($messageId, $this->validPayload());

        $row = DB::table('inbox_messages')->where('message_id', $messageId)->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->processed_at);
        $this->assertNotNull($row->created_at);
    }

    public function test_dispatches_signal_for_normalizable_event(): void
    {
        $messageId = Str::uuid()->toString();
        $correlationId = Str::uuid()->toString();
        $payload = $this->validPayload(correlationId: $correlationId);

        $this->signalDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($event, $cid) use ($correlationId): bool {
                return $event->paymentId === self::PAYMENT_UUID
                    && $event->internalStatus === 'captured'
                    && $cid === $correlationId;
            });

        $this->processor()->execute($messageId, $payload);
    }

    // -----------------------------------------------------------------------
    // Deduplication / idempotency
    // -----------------------------------------------------------------------

    public function test_skips_processing_when_message_already_in_inbox(): void
    {
        $messageId = Str::uuid()->toString();
        $now = now();

        DB::table('inbox_messages')->insert([
            'message_id' => $messageId,
            'processed_at' => $now,
            'created_at' => $now,
        ]);

        $this->signalDispatcher->shouldNotReceive('dispatch');

        $this->processor()->execute($messageId, $this->validPayload());

        $count = DB::table('inbox_messages')->where('message_id', $messageId)->count();
        $this->assertSame(1, $count);
    }

    public function test_two_different_message_ids_are_both_recorded(): void
    {
        $firstId = Str::uuid()->toString();
        $secondId = Str::uuid()->toString();

        $this->signalDispatcher->shouldReceive('dispatch')->twice();

        $this->processor()->execute($firstId, $this->validPayload(eventId: 'evt_001'));
        $this->processor()->execute($secondId, $this->validPayload(eventId: 'evt_002'));

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $firstId]);
        $this->assertDatabaseHas('inbox_messages', ['message_id' => $secondId]);
        $this->assertSame(2, DB::table('inbox_messages')->count());
    }

    // -----------------------------------------------------------------------
    // Dead workflow — no retry, inbox committed
    // -----------------------------------------------------------------------

    public function test_commits_inbox_and_logs_warning_when_workflow_not_found(): void
    {
        $messageId = Str::uuid()->toString();

        $this->signalDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new DeadWorkflowException('Workflow not found', 'workflow_not_found'));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'undeliverable'));

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->processor()->execute($messageId, $this->validPayload());

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $messageId]);
    }

    public function test_warning_log_includes_required_fields_when_workflow_dead(): void
    {
        $messageId = Str::uuid()->toString();
        $correlationId = Str::uuid()->toString();

        $this->signalDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new DeadWorkflowException('dead', 'workflow_already_closed'));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg, array $context) use ($correlationId): bool {
                return str_contains($msg, 'undeliverable')
                    && isset($context['payment_id'], $context['correlation_id'], $context['signal_type'], $context['provider_event_id'])
                    && $context['correlation_id'] === $correlationId
                    && $context['reason'] === 'workflow_already_closed';
            });

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->processor()->execute($messageId, $this->validPayload(correlationId: $correlationId));
    }

    public function test_publishes_undeliverable_outbox_event_when_workflow_not_found(): void
    {
        $messageId = Str::uuid()->toString();
        $correlationId = Str::uuid()->toString();

        $this->signalDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new DeadWorkflowException('dead', 'workflow_not_found'));

        Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => str_contains($msg, 'undeliverable'));
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->processor()->execute($messageId, $this->validPayload(correlationId: $correlationId));

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'webhook.signal.undeliverable.v1']);
    }

    public function test_undeliverable_outbox_event_contains_required_fields(): void
    {
        $messageId = Str::uuid()->toString();
        $correlationId = Str::uuid()->toString();

        $this->signalDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new DeadWorkflowException('dead', 'workflow_already_closed'));

        Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => str_contains($msg, 'undeliverable'));
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->processor()->execute($messageId, $this->validPayload(correlationId: $correlationId));

        $row = DB::table('outbox_events')
            ->where('event_type', 'webhook.signal.undeliverable.v1')
            ->first();

        $this->assertNotNull($row);

        $payload = json_decode($row->payload, true);

        $this->assertSame(self::PAYMENT_UUID, $payload['payment_id']);
        $this->assertSame($correlationId, $payload['correlation_id']);
        $this->assertSame('captured', $payload['normalized_status']);
        $this->assertSame('workflow_already_closed', $payload['reason']);
        $this->assertArrayHasKey('provider_event_id', $payload);
        $this->assertArrayHasKey('occurred_at', $payload);
    }

    public function test_undeliverable_outbox_event_not_written_when_signal_succeeds(): void
    {
        $messageId = Str::uuid()->toString();

        $this->signalDispatcher->shouldReceive('dispatch')->once();

        $this->processor()->execute($messageId, $this->validPayload());

        $this->assertDatabaseMissing('outbox_events', ['event_type' => 'webhook.signal.undeliverable.v1']);
    }

    public function test_undeliverable_outbox_event_not_written_on_transient_error(): void
    {
        $messageId = Str::uuid()->toString();

        $this->signalDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        try {
            $this->processor()->execute($messageId, $this->validPayload());
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseMissing('outbox_events', ['event_type' => 'webhook.signal.undeliverable.v1']);
    }

    // -----------------------------------------------------------------------
    // Transient failure — exception propagates, inbox NOT committed
    // -----------------------------------------------------------------------

    public function test_propagates_transient_error_and_does_not_commit_inbox(): void
    {
        $messageId = Str::uuid()->toString();

        $this->signalDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        try {
            $this->processor()->execute($messageId, $this->validPayload());
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            // expected — message will be nacked and requeued
        }

        $this->assertDatabaseMissing('inbox_messages', ['message_id' => $messageId]);
    }

    // -----------------------------------------------------------------------
    // No signal when event is unmappable
    // -----------------------------------------------------------------------

    public function test_commits_inbox_without_signal_when_provider_is_unknown(): void
    {
        $messageId = Str::uuid()->toString();

        $this->signalDispatcher->shouldNotReceive('dispatch');

        $this->processor()->execute($messageId, $this->validPayload(provider: 'unknown-psp'));

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $messageId]);
    }

    // -----------------------------------------------------------------------
    // Outbox events
    // -----------------------------------------------------------------------

    public function test_writes_outbox_event_for_normalizable_webhook(): void
    {
        $messageId = Str::uuid()->toString();
        $rawEventId = Str::uuid()->toString();

        $this->signalDispatcher->shouldReceive('dispatch')->once();

        $this->processor()->execute($messageId, $this->validPayload(rawEventId: $rawEventId));

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_type' => 'normalized_webhook_event',
            'event_type' => 'provider.webhook_signal_received.v1',
        ]);
    }

    public function test_outbox_event_contains_correct_fields(): void
    {
        $messageId = Str::uuid()->toString();
        $rawEventId = Str::uuid()->toString();
        $correlationId = Str::uuid()->toString();
        $paymentId = self::PAYMENT_UUID;

        $this->signalDispatcher->shouldReceive('dispatch')->once();

        $this->processor()->execute($messageId, $this->validPayload(
            rawEventId: $rawEventId,
            correlationId: $correlationId,
            paymentUuid: $paymentId,
        ));

        $row = DB::table('outbox_events')
            ->where('event_type', 'provider.webhook_signal_received.v1')
            ->first();

        $this->assertNotNull($row);

        $payload = json_decode($row->payload, true);

        $this->assertSame($correlationId, $payload['correlation_id']);
        $this->assertSame($rawEventId, $payload['raw_event_id']);
        $this->assertSame('mock', $payload['provider']);
        $this->assertSame($paymentId, $payload['payment_id']);
        $this->assertSame('payment_captured', $payload['signal_type']);
        $this->assertArrayHasKey('normalized_at', $payload);
        $this->assertArrayHasKey('signal_id', $payload);
    }

    public function test_does_not_write_outbox_event_when_provider_is_unknown(): void
    {
        $messageId = Str::uuid()->toString();

        $this->signalDispatcher->shouldNotReceive('dispatch');

        $this->processor()->execute($messageId, $this->validPayload(provider: 'unknown-psp'));

        $this->assertDatabaseMissing('outbox_events', ['event_type' => 'provider.webhook_signal_received.v1']);
    }

    public function test_outbox_event_written_even_when_workflow_is_dead(): void
    {
        $messageId = Str::uuid()->toString();

        $this->signalDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new DeadWorkflowException('Workflow not found', 'workflow_not_found'));

        Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => str_contains($msg, 'undeliverable'));
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->processor()->execute($messageId, $this->validPayload());

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'provider.webhook_signal_received.v1']);
    }

    public function test_outbox_event_not_written_when_transient_error_propagates(): void
    {
        $messageId = Str::uuid()->toString();

        $this->signalDispatcher
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        try {
            $this->processor()->execute($messageId, $this->validPayload());
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseMissing('outbox_events', ['event_type' => 'provider.webhook_signal_received.v1']);
    }

    // -----------------------------------------------------------------------
    // Payload variations
    // -----------------------------------------------------------------------

    public function test_processes_message_with_missing_optional_correlation_id(): void
    {
        $messageId = Str::uuid()->toString();
        $payload = $this->validPayload();
        unset($payload['correlation_id']);

        $this->signalDispatcher->shouldReceive('dispatch')->once();

        $this->processor()->execute($messageId, $payload);

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $messageId]);
    }

    public function test_processes_message_with_extra_unknown_fields(): void
    {
        $messageId = Str::uuid()->toString();
        $payload = array_merge($this->validPayload(), ['unexpected_field' => 'ignored']);

        $this->signalDispatcher->shouldReceive('dispatch')->once();

        $this->processor()->execute($messageId, $payload);

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $messageId]);
    }
}
