<?php

namespace Tests\Unit\Infrastructure\Outbox;

use App\Infrastructure\Outbox\OutboxEvent;
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

    private function makeEvent(array $overrides = []): OutboxEvent
    {
        $event = new OutboxEvent(array_merge([
            'aggregate_type' => 'Payment',
            'aggregate_id' => 'pay-001',
            'event_type' => 'payment.initiated.v1',
            'payload' => [
                'payment_id' => 'pay-001',
                'correlation_id' => 'corr-abc-123',
                'occurred_at' => '2026-04-10T12:00:00+00:00',
            ],
        ], $overrides));

        // Assign an ID as if Eloquent had set it.
        $event->id = '01960000-0000-0000-0000-000000000001';

        return $event;
    }

    public function test_schema_version_is_one(): void
    {
        $envelope = $this->builder->build($this->makeEvent());

        $this->assertSame('1', $envelope['schema_version']);
    }

    public function test_message_id_equals_outbox_event_id(): void
    {
        $event = $this->makeEvent();
        $envelope = $this->builder->build($event);

        $this->assertSame($event->id, $envelope['message_id']);
    }

    public function test_source_service_is_payment_domain(): void
    {
        $envelope = $this->builder->build($this->makeEvent());

        $this->assertSame('payment-domain', $envelope['source_service']);
    }

    public function test_event_type_strips_version_suffix(): void
    {
        $envelope = $this->builder->build($this->makeEvent(['event_type' => 'payment.initiated.v1']));

        $this->assertSame('payment.initiated', $envelope['event_type']);
    }

    public function test_event_type_strips_higher_version_suffix(): void
    {
        $envelope = $this->builder->build($this->makeEvent(['event_type' => 'payment.captured.v12']));

        $this->assertSame('payment.captured', $envelope['event_type']);
    }

    public function test_correlation_id_is_propagated_from_payload(): void
    {
        $envelope = $this->builder->build($this->makeEvent());

        $this->assertSame('corr-abc-123', $envelope['correlation_id']);
    }

    public function test_correlation_id_defaults_to_empty_string_when_missing(): void
    {
        $event = $this->makeEvent(['payload' => ['payment_id' => 'pay-001']]);
        $envelope = $this->builder->build($event);

        $this->assertSame('', $envelope['correlation_id']);
    }

    public function test_occurred_at_is_propagated_from_payload(): void
    {
        $envelope = $this->builder->build($this->makeEvent());

        $this->assertSame('2026-04-10T12:00:00+00:00', $envelope['occurred_at']);
    }

    public function test_payload_is_the_full_event_payload(): void
    {
        $event = $this->makeEvent();
        $envelope = $this->builder->build($event);

        $this->assertSame($event->payload, $envelope['payload']);
    }

    public function test_causation_id_is_null_when_missing_from_payload(): void
    {
        $envelope = $this->builder->build($this->makeEvent());

        $this->assertNull($envelope['causation_id']);
    }

    public function test_causation_id_is_propagated_from_payload_when_present(): void
    {
        $event = $this->makeEvent([
            'payload' => [
                'payment_id' => 'pay-001',
                'correlation_id' => 'corr-001',
                'causation_id' => 'cause-001',
                'occurred_at' => '2026-04-10T12:00:00+00:00',
            ],
        ]);

        $envelope = $this->builder->build($event);

        $this->assertSame('cause-001', $envelope['causation_id']);
    }
}
