<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\ProjectPaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectPaymentEventTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT_ID = '00000000-0000-0000-0000-000000000001';

    private const PROVIDER_ID = 'mock-provider';

    private function envelope(
        string $eventType = 'payment.initiated',
        ?string $paymentId = null,
        string $merchantId = self::MERCHANT_ID,
        ?string $providerId = self::PROVIDER_ID,
        int $amount = 10000,
        string $currency = 'USD',
        string $status = 'created',
        ?string $occurredAt = null,
        array $extra = [],
    ): array {
        $pid = $paymentId ?? Str::ulid()->toString();
        $at = $occurredAt ?? '2026-04-23T10:00:00+00:00';

        return [
            'schema_version' => '1',
            'message_id' => Str::uuid()->toString(),
            'correlation_id' => Str::uuid()->toString(),
            'causation_id' => null,
            'source_service' => 'payment-domain',
            'occurred_at' => $at,
            'event_type' => $eventType,
            'payload' => array_merge([
                'payment_id' => $pid,
                'merchant_id' => $merchantId,
                'provider_id' => $providerId,
                'amount' => ['value' => $amount, 'currency' => $currency],
                'status' => $status,
                'correlation_id' => Str::uuid()->toString(),
                'occurred_at' => $at,
            ], $extra),
        ];
    }

    private function projector(): ProjectPaymentEvent
    {
        return $this->app->make(ProjectPaymentEvent::class);
    }

    // -----------------------------------------------------------------------
    // Inbox / deduplication
    // -----------------------------------------------------------------------

    public function test_records_message_in_inbox_on_first_processing(): void
    {
        $envelope = $this->envelope();
        $messageId = $envelope['message_id'];

        $this->projector()->execute($messageId, $envelope);

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $messageId]);
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

        // payment_projections must remain empty — processor was skipped
        $this->assertSame(0, DB::table('payment_projections')->count());
    }

    public function test_two_distinct_messages_are_both_recorded(): void
    {
        $env1 = $this->envelope(paymentId: Str::ulid()->toString());
        $env2 = $this->envelope(paymentId: Str::ulid()->toString());

        $this->projector()->execute($env1['message_id'], $env1);
        $this->projector()->execute($env2['message_id'], $env2);

        $this->assertSame(2, DB::table('inbox_messages')->count());
    }

    // -----------------------------------------------------------------------
    // payment_projections — searchable read model
    // -----------------------------------------------------------------------

    public function test_creates_payment_projection_on_initiated_event(): void
    {
        $envelope = $this->envelope(
            eventType: 'payment.initiated',
            status: 'created',
        );
        $paymentId = $envelope['payload']['payment_id'];

        $this->projector()->execute($envelope['message_id'], $envelope);

        $this->assertDatabaseHas('payment_projections', [
            'id' => $paymentId,
            'merchant_id' => self::MERCHANT_ID,
            'status' => 'created',
            'amount' => 10000,
            'currency' => 'USD',
            'provider_id' => self::PROVIDER_ID,
        ]);
    }

    public function test_updates_status_on_captured_event(): void
    {
        $paymentId = Str::ulid()->toString();

        $initiated = $this->envelope(paymentId: $paymentId, eventType: 'payment.initiated', status: 'created');
        $captured = $this->envelope(paymentId: $paymentId, eventType: 'payment.captured', status: 'captured');

        $this->projector()->execute($initiated['message_id'], $initiated);
        $this->projector()->execute($captured['message_id'], $captured);

        $this->assertDatabaseHas('payment_projections', [
            'id' => $paymentId,
            'status' => 'captured',
        ]);
    }

    public function test_sets_authorized_at_on_authorized_event(): void
    {
        $paymentId = Str::ulid()->toString();
        $at = '2026-04-23T11:00:00+00:00';

        $envelope = $this->envelope(
            eventType: 'payment.authorized',
            paymentId: $paymentId,
            status: 'authorized',
            occurredAt: $at,
        );

        $this->projector()->execute($envelope['message_id'], $envelope);

        $row = DB::table('payment_projections')->where('id', $paymentId)->first();
        $this->assertNotNull($row->authorized_at);
    }

    public function test_sets_captured_at_on_captured_event(): void
    {
        $paymentId = Str::ulid()->toString();

        $envelope = $this->envelope(eventType: 'payment.captured', paymentId: $paymentId, status: 'captured');
        $this->projector()->execute($envelope['message_id'], $envelope);

        $row = DB::table('payment_projections')->where('id', $paymentId)->first();
        $this->assertNotNull($row->captured_at);
    }

    public function test_sets_failed_at_on_failed_event(): void
    {
        $paymentId = Str::ulid()->toString();

        $envelope = $this->envelope(eventType: 'payment.failed', paymentId: $paymentId, status: 'failed');
        $this->projector()->execute($envelope['message_id'], $envelope);

        $row = DB::table('payment_projections')->where('id', $paymentId)->first();
        $this->assertNotNull($row->failed_at);
    }

    public function test_sets_refunded_at_on_refunded_event(): void
    {
        $paymentId = Str::ulid()->toString();

        $envelope = $this->envelope(eventType: 'payment.refunded', paymentId: $paymentId, status: 'refunded');
        $this->projector()->execute($envelope['message_id'], $envelope);

        $row = DB::table('payment_projections')->where('id', $paymentId)->first();
        $this->assertNotNull($row->refunded_at);
    }

    public function test_does_not_overwrite_existing_authorized_at(): void
    {
        $paymentId = Str::ulid()->toString();

        $authorized = $this->envelope(
            eventType: 'payment.authorized',
            paymentId: $paymentId,
            status: 'authorized',
            occurredAt: '2026-04-23T10:00:00+00:00',
        );
        $captured = $this->envelope(
            eventType: 'payment.captured',
            paymentId: $paymentId,
            status: 'captured',
        );

        $this->projector()->execute($authorized['message_id'], $authorized);
        $this->projector()->execute($captured['message_id'], $captured);

        $row = DB::table('payment_projections')->where('id', $paymentId)->first();
        // authorized_at must still be set from the first event
        $this->assertNotNull($row->authorized_at);
    }

    // -----------------------------------------------------------------------
    // merchant_payment_summaries
    // -----------------------------------------------------------------------

    public function test_creates_merchant_summary_on_first_payment(): void
    {
        $envelope = $this->envelope(eventType: 'payment.initiated', status: 'created');
        $this->projector()->execute($envelope['message_id'], $envelope);

        $this->assertDatabaseHas('merchant_payment_summaries', [
            'merchant_id' => self::MERCHANT_ID,
            'total_count' => 1,
        ]);
    }

    public function test_merchant_summary_total_volume_reflects_payment_amount(): void
    {
        $envelope = $this->envelope(amount: 5000, status: 'created');
        $this->projector()->execute($envelope['message_id'], $envelope);

        $row = DB::table('merchant_payment_summaries')
            ->where('merchant_id', self::MERCHANT_ID)
            ->first();
        $this->assertSame(5000, (int) $row->total_volume_cents);
    }

    public function test_merchant_summary_captured_count_increments_on_capture(): void
    {
        $paymentId = Str::ulid()->toString();

        $initiated = $this->envelope(paymentId: $paymentId, status: 'created');
        $captured = $this->envelope(paymentId: $paymentId, eventType: 'payment.captured', status: 'captured');

        $this->projector()->execute($initiated['message_id'], $initiated);
        $this->projector()->execute($captured['message_id'], $captured);

        $row = DB::table('merchant_payment_summaries')
            ->where('merchant_id', self::MERCHANT_ID)
            ->first();

        $this->assertSame(1, (int) $row->captured_count);
    }

    public function test_merchant_summary_failed_count_increments_on_failure(): void
    {
        $paymentId = Str::ulid()->toString();

        $initiated = $this->envelope(paymentId: $paymentId, status: 'created');
        $failed = $this->envelope(paymentId: $paymentId, eventType: 'payment.failed', status: 'failed');

        $this->projector()->execute($initiated['message_id'], $initiated);
        $this->projector()->execute($failed['message_id'], $failed);

        $row = DB::table('merchant_payment_summaries')
            ->where('merchant_id', self::MERCHANT_ID)
            ->first();

        $this->assertSame(1, (int) $row->failed_count);
    }

    public function test_merchant_summary_aggregates_multiple_payments(): void
    {
        $p1 = Str::ulid()->toString();
        $p2 = Str::ulid()->toString();

        $e1 = $this->envelope(paymentId: $p1, amount: 1000, status: 'created');
        $e2 = $this->envelope(paymentId: $p2, amount: 2000, status: 'created');

        $this->projector()->execute($e1['message_id'], $e1);
        $this->projector()->execute($e2['message_id'], $e2);

        $row = DB::table('merchant_payment_summaries')
            ->where('merchant_id', self::MERCHANT_ID)
            ->first();

        $this->assertSame(2, (int) $row->total_count);
        $this->assertSame(3000, (int) $row->total_volume_cents);
    }

    // -----------------------------------------------------------------------
    // provider_performance_summaries
    // -----------------------------------------------------------------------

    public function test_creates_provider_summary_on_first_event(): void
    {
        $envelope = $this->envelope(status: 'created');
        $this->projector()->execute($envelope['message_id'], $envelope);

        $this->assertDatabaseHas('provider_performance_summaries', [
            'provider_id' => self::PROVIDER_ID,
            'total_attempts' => 1,
        ]);
    }

    public function test_provider_summary_captured_count_reflects_captures(): void
    {
        $paymentId = Str::ulid()->toString();

        $e1 = $this->envelope(paymentId: $paymentId, status: 'created');
        $e2 = $this->envelope(paymentId: $paymentId, eventType: 'payment.captured', status: 'captured');

        $this->projector()->execute($e1['message_id'], $e1);
        $this->projector()->execute($e2['message_id'], $e2);

        $row = DB::table('provider_performance_summaries')
            ->where('provider_id', self::PROVIDER_ID)
            ->first();

        $this->assertSame(1, (int) $row->captured_count);
    }

    public function test_provider_summary_failed_count_reflects_failures(): void
    {
        $paymentId = Str::ulid()->toString();

        $e1 = $this->envelope(paymentId: $paymentId, status: 'created');
        $e2 = $this->envelope(paymentId: $paymentId, eventType: 'payment.failed', status: 'failed');

        $this->projector()->execute($e1['message_id'], $e1);
        $this->projector()->execute($e2['message_id'], $e2);

        $row = DB::table('provider_performance_summaries')
            ->where('provider_id', self::PROVIDER_ID)
            ->first();

        $this->assertSame(1, (int) $row->failed_count);
    }

    public function test_skips_provider_summary_when_provider_id_is_null(): void
    {
        $envelope = $this->envelope(providerId: null, status: 'created');
        $envelope['payload']['provider_id'] = null;

        $this->projector()->execute($envelope['message_id'], $envelope);

        $this->assertSame(0, DB::table('provider_performance_summaries')->count());
    }

    // -----------------------------------------------------------------------
    // daily_aggregates
    // -----------------------------------------------------------------------

    public function test_creates_daily_aggregate_on_initiated_event(): void
    {
        $envelope = $this->envelope(
            eventType: 'payment.initiated',
            amount: 10000,
            currency: 'USD',
            status: 'created',
            occurredAt: '2026-04-23T10:00:00+00:00',
        );

        $this->projector()->execute($envelope['message_id'], $envelope);

        $this->assertDatabaseHas('daily_aggregates', [
            'date' => '2026-04-23',
            'currency' => 'USD',
            'payments_initiated' => 1,
            'volume_initiated_cents' => 10000,
        ]);
    }

    public function test_increments_payments_captured_on_capture_event(): void
    {
        $paymentId = Str::ulid()->toString();

        $e1 = $this->envelope(
            paymentId: $paymentId,
            eventType: 'payment.initiated',
            status: 'created',
            occurredAt: '2026-04-23T10:00:00+00:00',
        );
        $e2 = $this->envelope(
            paymentId: $paymentId,
            eventType: 'payment.captured',
            amount: 10000,
            status: 'captured',
            occurredAt: '2026-04-23T10:05:00+00:00',
        );

        $this->projector()->execute($e1['message_id'], $e1);
        $this->projector()->execute($e2['message_id'], $e2);

        $this->assertDatabaseHas('daily_aggregates', [
            'date' => '2026-04-23',
            'currency' => 'USD',
            'payments_captured' => 1,
            'volume_captured_cents' => 10000,
        ]);
    }

    public function test_increments_payments_failed_on_failed_event(): void
    {
        $envelope = $this->envelope(
            eventType: 'payment.failed',
            status: 'failed',
            occurredAt: '2026-04-23T10:00:00+00:00',
        );

        $this->projector()->execute($envelope['message_id'], $envelope);

        $this->assertDatabaseHas('daily_aggregates', [
            'date' => '2026-04-23',
            'currency' => 'USD',
            'payments_failed' => 1,
        ]);
    }

    public function test_increments_payments_cancelled_on_cancelled_event(): void
    {
        $envelope = $this->envelope(
            eventType: 'payment.cancelled',
            status: 'cancelled',
            occurredAt: '2026-04-23T10:00:00+00:00',
        );

        $this->projector()->execute($envelope['message_id'], $envelope);

        $this->assertDatabaseHas('daily_aggregates', [
            'date' => '2026-04-23',
            'currency' => 'USD',
            'payments_cancelled' => 1,
        ]);
    }

    public function test_aggregates_multiple_payments_on_same_date(): void
    {
        $date = '2026-04-23T10:00:00+00:00';

        $e1 = $this->envelope(
            paymentId: Str::ulid()->toString(),
            eventType: 'payment.initiated',
            amount: 5000,
            status: 'created',
            occurredAt: $date,
        );
        $e2 = $this->envelope(
            paymentId: Str::ulid()->toString(),
            eventType: 'payment.initiated',
            amount: 3000,
            status: 'created',
            occurredAt: $date,
        );

        $this->projector()->execute($e1['message_id'], $e1);
        $this->projector()->execute($e2['message_id'], $e2);

        $this->assertDatabaseHas('daily_aggregates', [
            'date' => '2026-04-23',
            'currency' => 'USD',
            'payments_initiated' => 2,
            'volume_initiated_cents' => 8000,
        ]);
    }

    public function test_daily_aggregates_are_separated_by_date(): void
    {
        $e1 = $this->envelope(
            paymentId: Str::ulid()->toString(),
            eventType: 'payment.initiated',
            status: 'created',
            occurredAt: '2026-04-22T10:00:00+00:00',
        );
        $e2 = $this->envelope(
            paymentId: Str::ulid()->toString(),
            eventType: 'payment.initiated',
            status: 'created',
            occurredAt: '2026-04-23T10:00:00+00:00',
        );

        $this->projector()->execute($e1['message_id'], $e1);
        $this->projector()->execute($e2['message_id'], $e2);

        $this->assertSame(2, DB::table('daily_aggregates')->count());
        $this->assertDatabaseHas('daily_aggregates', ['date' => '2026-04-22', 'payments_initiated' => 1]);
        $this->assertDatabaseHas('daily_aggregates', ['date' => '2026-04-23', 'payments_initiated' => 1]);
    }

    public function test_does_not_update_daily_aggregates_for_untracked_event_types(): void
    {
        $envelope = $this->envelope(eventType: 'payment.pending_provider', status: 'pending_provider');
        $this->projector()->execute($envelope['message_id'], $envelope);

        $this->assertSame(0, DB::table('daily_aggregates')->count());
    }

    // -----------------------------------------------------------------------
    // Idempotency — replay same message produces same state
    // -----------------------------------------------------------------------

    public function test_replaying_same_message_does_not_double_count_daily_aggregate(): void
    {
        $envelope = $this->envelope(
            eventType: 'payment.initiated',
            amount: 10000,
            status: 'created',
            occurredAt: '2026-04-23T10:00:00+00:00',
        );

        $this->projector()->execute($envelope['message_id'], $envelope);
        $this->projector()->execute($envelope['message_id'], $envelope); // replay

        $this->assertDatabaseHas('daily_aggregates', [
            'date' => '2026-04-23',
            'payments_initiated' => 1,
            'volume_initiated_cents' => 10000,
        ]);
    }

    public function test_replaying_same_message_does_not_create_duplicate_projection(): void
    {
        $envelope = $this->envelope(status: 'created');

        $this->projector()->execute($envelope['message_id'], $envelope);
        $this->projector()->execute($envelope['message_id'], $envelope);

        $this->assertSame(1, DB::table('payment_projections')->count());
    }
}
