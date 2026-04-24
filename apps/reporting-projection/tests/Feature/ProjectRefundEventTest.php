<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\ProjectRefundEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectRefundEventTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT_ID = '00000000-0000-0000-0000-000000000002';

    private const PAYMENT_ID = '01HWZQG3H6K7J8MXNP4QR5T6VW';

    private function envelope(
        string $eventType = 'refund.succeeded',
        ?string $refundId = null,
        string $paymentId = self::PAYMENT_ID,
        string $merchantId = self::MERCHANT_ID,
        int $amount = 5000,
        string $currency = 'USD',
        string $status = 'succeeded',
        string $occurredAt = '2026-04-23T10:00:00+00:00',
    ): array {
        return [
            'schema_version' => '1',
            'message_id' => Str::uuid()->toString(),
            'correlation_id' => Str::uuid()->toString(),
            'causation_id' => null,
            'source_service' => 'payment-domain',
            'occurred_at' => $occurredAt,
            'event_type' => $eventType,
            'payload' => [
                'refund_id' => $refundId ?? Str::ulid()->toString(),
                'payment_id' => $paymentId,
                'merchant_id' => $merchantId,
                'provider_id' => 'mock-provider',
                'amount' => ['value' => $amount, 'currency' => $currency],
                'status' => $status,
                'correlation_id' => Str::uuid()->toString(),
                'occurred_at' => $occurredAt,
            ],
        ];
    }

    private function projector(): ProjectRefundEvent
    {
        return $this->app->make(ProjectRefundEvent::class);
    }

    // -----------------------------------------------------------------------
    // Inbox / deduplication
    // -----------------------------------------------------------------------

    public function test_records_message_in_inbox_on_first_processing(): void
    {
        $envelope = $this->envelope();
        $this->projector()->execute($envelope['message_id'], $envelope);

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $envelope['message_id']]);
    }

    public function test_skips_processing_when_message_already_in_inbox(): void
    {
        $envelope = $this->envelope();
        $messageId = $envelope['message_id'];

        DB::table('inbox_messages')->insert([
            'message_id' => $messageId,
            'processed_at' => now(),
            'created_at' => now(),
        ]);

        $this->projector()->execute($messageId, $envelope);

        $this->assertSame(0, DB::table('daily_aggregates')->count());
    }

    public function test_two_distinct_messages_are_both_recorded(): void
    {
        $e1 = $this->envelope(refundId: Str::ulid()->toString());
        $e2 = $this->envelope(refundId: Str::ulid()->toString());

        $this->projector()->execute($e1['message_id'], $e1);
        $this->projector()->execute($e2['message_id'], $e2);

        $this->assertSame(2, DB::table('inbox_messages')->count());
    }

    // -----------------------------------------------------------------------
    // daily_aggregates — refund.succeeded
    // -----------------------------------------------------------------------

    public function test_increments_refunds_succeeded_on_succeeded_event(): void
    {
        $envelope = $this->envelope(
            eventType: 'refund.succeeded',
            amount: 5000,
            currency: 'USD',
            occurredAt: '2026-04-23T10:00:00+00:00',
        );

        $this->projector()->execute($envelope['message_id'], $envelope);

        $this->assertDatabaseHas('daily_aggregates', [
            'date' => '2026-04-23',
            'currency' => 'USD',
            'refunds_succeeded' => 1,
            'refund_volume_cents' => 5000,
        ]);
    }

    public function test_aggregates_multiple_refunds_on_same_date(): void
    {
        $e1 = $this->envelope(amount: 2000, occurredAt: '2026-04-23T10:00:00+00:00');
        $e2 = $this->envelope(amount: 3000, occurredAt: '2026-04-23T11:00:00+00:00');

        $this->projector()->execute($e1['message_id'], $e1);
        $this->projector()->execute($e2['message_id'], $e2);

        $this->assertDatabaseHas('daily_aggregates', [
            'date' => '2026-04-23',
            'currency' => 'USD',
            'refunds_succeeded' => 2,
            'refund_volume_cents' => 5000,
        ]);
    }

    public function test_refund_daily_aggregate_is_separated_by_date(): void
    {
        $e1 = $this->envelope(amount: 1000, occurredAt: '2026-04-22T10:00:00+00:00');
        $e2 = $this->envelope(amount: 2000, occurredAt: '2026-04-23T10:00:00+00:00');

        $this->projector()->execute($e1['message_id'], $e1);
        $this->projector()->execute($e2['message_id'], $e2);

        $this->assertSame(2, DB::table('daily_aggregates')->count());
        $this->assertDatabaseHas('daily_aggregates', ['date' => '2026-04-22', 'refund_volume_cents' => 1000]);
        $this->assertDatabaseHas('daily_aggregates', ['date' => '2026-04-23', 'refund_volume_cents' => 2000]);
    }

    public function test_does_not_update_daily_aggregate_for_non_succeeded_events(): void
    {
        $events = ['refund.initiated', 'refund.pending_provider', 'refund.failed'];

        foreach ($events as $eventType) {
            $envelope = $this->envelope(eventType: $eventType, status: 'pending');
            $this->projector()->execute($envelope['message_id'], $envelope);
        }

        $this->assertSame(0, DB::table('daily_aggregates')->count());
    }

    // -----------------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------------

    public function test_replaying_same_message_does_not_double_count(): void
    {
        $envelope = $this->envelope(amount: 5000, occurredAt: '2026-04-23T10:00:00+00:00');

        $this->projector()->execute($envelope['message_id'], $envelope);
        $this->projector()->execute($envelope['message_id'], $envelope); // replay

        $this->assertDatabaseHas('daily_aggregates', [
            'date' => '2026-04-23',
            'refunds_succeeded' => 1,
            'refund_volume_cents' => 5000,
        ]);
    }
}
