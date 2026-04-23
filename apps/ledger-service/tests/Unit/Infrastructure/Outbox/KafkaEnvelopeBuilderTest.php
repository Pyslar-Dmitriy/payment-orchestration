<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Outbox;

use App\Infrastructure\Outbox\OutboxMessage;
use App\Infrastructure\Outbox\Publisher\Kafka\KafkaEnvelopeBuilder;
use Tests\TestCase;

class KafkaEnvelopeBuilderTest extends TestCase
{
    private KafkaEnvelopeBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new KafkaEnvelopeBuilder;
    }

    private function makeMessage(array $overrides = []): OutboxMessage
    {
        $message = new OutboxMessage(array_merge([
            'aggregate_type' => 'LedgerTransaction',
            'aggregate_id' => 'txn-001',
            'event_type' => 'ledger.entry_posted.v1',
            'payload' => [
                'entry_id' => 'txn-001',
                'payment_id' => '00000000-0000-0000-0000-000000000001',
                'merchant_id' => 'merchant-abc',
                'posting_type' => 'capture',
                'lines' => [],
                'idempotency_key' => 'capture:00000000-0000-0000-0000-000000000001',
                'posted_at' => '2026-04-21T12:00:00+00:00',
                'correlation_id' => 'corr-abc-123',
                'occurred_at' => '2026-04-21T12:00:00+00:00',
            ],
        ], $overrides));

        $message->id = '01960000-0000-0000-0000-000000000001';

        return $message;
    }

    public function test_schema_version_is_one(): void
    {
        $envelope = $this->builder->build($this->makeMessage());

        $this->assertSame('1', $envelope['schema_version']);
    }

    public function test_message_id_equals_outbox_message_id(): void
    {
        $message = $this->makeMessage();
        $envelope = $this->builder->build($message);

        $this->assertSame($message->id, $envelope['message_id']);
    }

    public function test_source_service_is_ledger_service(): void
    {
        $envelope = $this->builder->build($this->makeMessage());

        $this->assertSame('ledger-service', $envelope['source_service']);
    }

    public function test_event_type_strips_version_suffix(): void
    {
        $envelope = $this->builder->build($this->makeMessage(['event_type' => 'ledger.entry_posted.v1']));

        $this->assertSame('ledger.entry_posted', $envelope['event_type']);
    }

    public function test_event_type_strips_higher_version_suffix(): void
    {
        $envelope = $this->builder->build($this->makeMessage(['event_type' => 'ledger.entry_posted.v12']));

        $this->assertSame('ledger.entry_posted', $envelope['event_type']);
    }

    public function test_correlation_id_is_propagated_from_payload(): void
    {
        $envelope = $this->builder->build($this->makeMessage());

        $this->assertSame('corr-abc-123', $envelope['correlation_id']);
    }

    public function test_correlation_id_defaults_to_empty_string_when_missing(): void
    {
        $message = $this->makeMessage(['payload' => ['entry_id' => 'txn-001']]);
        $envelope = $this->builder->build($message);

        $this->assertSame('', $envelope['correlation_id']);
    }

    public function test_occurred_at_is_propagated_from_payload(): void
    {
        $envelope = $this->builder->build($this->makeMessage());

        $this->assertSame('2026-04-21T12:00:00+00:00', $envelope['occurred_at']);
    }

    public function test_payload_is_the_full_message_payload(): void
    {
        $message = $this->makeMessage();
        $envelope = $this->builder->build($message);

        $this->assertSame($message->payload, $envelope['payload']);
    }

    public function test_causation_id_is_null_when_missing_from_payload(): void
    {
        $envelope = $this->builder->build($this->makeMessage());

        $this->assertNull($envelope['causation_id']);
    }

    public function test_causation_id_is_propagated_from_payload_when_present(): void
    {
        $message = $this->makeMessage([
            'payload' => [
                'entry_id' => 'txn-001',
                'correlation_id' => 'corr-001',
                'causation_id' => 'cause-001',
                'occurred_at' => '2026-04-21T12:00:00+00:00',
            ],
        ]);

        $envelope = $this->builder->build($message);

        $this->assertSame('cause-001', $envelope['causation_id']);
    }
}
