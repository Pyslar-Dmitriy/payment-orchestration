<?php

namespace Tests\Feature;

use App\Application\ProcessRawWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessRawWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(
        ?string $rawEventId = null,
        string $provider = 'mock',
        string $eventId = 'evt_001',
        ?string $correlationId = null,
    ): array {
        return [
            'raw_event_id' => $rawEventId ?? Str::uuid()->toString(),
            'provider' => $provider,
            'event_id' => $eventId,
            'correlation_id' => $correlationId ?? Str::uuid()->toString(),
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
        $payload = $this->validPayload();

        $this->processor()->execute($messageId, $payload);

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $messageId]);
    }

    public function test_inbox_entry_has_processed_at_and_created_at(): void
    {
        $messageId = Str::uuid()->toString();

        $this->processor()->execute($messageId, $this->validPayload());

        $row = DB::table('inbox_messages')->where('message_id', $messageId)->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->processed_at);
        $this->assertNotNull($row->created_at);
    }

    // -----------------------------------------------------------------------
    // Deduplication / idempotency
    // -----------------------------------------------------------------------

    public function test_skips_processing_when_message_already_in_inbox(): void
    {
        $messageId = Str::uuid()->toString();
        $payload = $this->validPayload();
        $now = now();

        DB::table('inbox_messages')->insert([
            'message_id' => $messageId,
            'processed_at' => $now,
            'created_at' => $now,
        ]);

        // Execute a second time — must not throw and must not insert a duplicate
        $this->processor()->execute($messageId, $payload);

        $count = DB::table('inbox_messages')->where('message_id', $messageId)->count();
        $this->assertSame(1, $count);
    }

    public function test_two_different_message_ids_are_both_recorded(): void
    {
        $firstId = Str::uuid()->toString();
        $secondId = Str::uuid()->toString();

        $this->processor()->execute($firstId, $this->validPayload(eventId: 'evt_001'));
        $this->processor()->execute($secondId, $this->validPayload(eventId: 'evt_002'));

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $firstId]);
        $this->assertDatabaseHas('inbox_messages', ['message_id' => $secondId]);
        $this->assertSame(2, DB::table('inbox_messages')->count());
    }

    // -----------------------------------------------------------------------
    // Payload variations
    // -----------------------------------------------------------------------

    public function test_processes_message_with_missing_optional_correlation_id(): void
    {
        $messageId = Str::uuid()->toString();
        $payload = $this->validPayload();
        unset($payload['correlation_id']);

        $this->processor()->execute($messageId, $payload);

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $messageId]);
    }

    public function test_processes_message_with_extra_unknown_fields(): void
    {
        $messageId = Str::uuid()->toString();
        $payload = array_merge($this->validPayload(), ['unexpected_field' => 'ignored']);

        $this->processor()->execute($messageId, $payload);

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $messageId]);
    }
}
