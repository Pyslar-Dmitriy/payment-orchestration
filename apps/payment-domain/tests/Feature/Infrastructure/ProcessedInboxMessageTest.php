<?php

namespace Tests\Feature\Infrastructure;

use App\Infrastructure\Inbox\ProcessedInboxMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProcessedInboxMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_a_processed_inbox_message(): void
    {
        $processedAt = now();

        ProcessedInboxMessage::create([
            'message_id' => 'msg-broker-abc-123',
            'message_type' => 'payment.capture.requested.v1',
            'payload' => ['payment_id' => '01j5x00000000000000000001'],
            'processed_at' => $processedAt,
        ]);

        $this->assertDatabaseHas('processed_inbox_messages', [
            'message_id' => 'msg-broker-abc-123',
            'message_type' => 'payment.capture.requested.v1',
        ]);
    }

    public function test_payload_is_cast_to_array(): void
    {
        ProcessedInboxMessage::create([
            'message_id' => 'msg-cast-test',
            'message_type' => 'test.event.v1',
            'payload' => ['key' => 'value', 'count' => 42],
            'processed_at' => now(),
        ]);

        $record = ProcessedInboxMessage::where('message_id', 'msg-cast-test')->firstOrFail();

        $this->assertIsArray($record->payload);
        $this->assertSame('value', $record->payload['key']);
    }

    public function test_processed_at_is_cast_to_carbon(): void
    {
        $processedAt = now()->startOfSecond();

        ProcessedInboxMessage::create([
            'message_id' => 'msg-datetime-test',
            'message_type' => 'test.event.v1',
            'payload' => [],
            'processed_at' => $processedAt,
        ]);

        $record = ProcessedInboxMessage::where('message_id', 'msg-datetime-test')->firstOrFail();

        $this->assertInstanceOf(Carbon::class, $record->processed_at);
        $this->assertTrue($processedAt->equalTo($record->processed_at));
    }

    public function test_duplicate_message_id_violates_unique_constraint(): void
    {
        ProcessedInboxMessage::create([
            'message_id' => 'msg-duplicate',
            'message_type' => 'test.event.v1',
            'payload' => [],
            'processed_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        ProcessedInboxMessage::create([
            'message_id' => 'msg-duplicate',
            'message_type' => 'test.event.v1',
            'payload' => [],
            'processed_at' => now(),
        ]);
    }

    public function test_updated_at_is_not_set(): void
    {
        $record = ProcessedInboxMessage::create([
            'message_id' => 'msg-no-updated-at',
            'message_type' => 'test.event.v1',
            'payload' => [],
            'processed_at' => now(),
        ]);

        $this->assertNull($record->updated_at);
    }
}
