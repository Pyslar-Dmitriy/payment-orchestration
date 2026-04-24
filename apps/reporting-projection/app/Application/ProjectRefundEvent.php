<?php

declare(strict_types=1);

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProjectRefundEvent
{
    /**
     * Project a refund lifecycle Kafka envelope into the relevant read models.
     *
     * Only refund.succeeded events update the daily_aggregates; other refund
     * status transitions are acknowledged and deduplicated but require no
     * further projection work at this stage.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function execute(string $messageId, array $envelope): void
    {
        $alreadyProcessed = DB::table('inbox_messages')
            ->where('message_id', $messageId)
            ->exists();

        if ($alreadyProcessed) {
            Log::info('Skipping duplicate refund event', [
                'message_id' => $messageId,
                'event_type' => $envelope['event_type'] ?? null,
            ]);

            return;
        }

        $eventType = (string) ($envelope['event_type'] ?? '');
        $payload = (array) ($envelope['payload'] ?? []);
        $occurredAt = (string) ($envelope['occurred_at'] ?? now()->toIso8601String());

        DB::transaction(function () use ($messageId, $eventType, $payload, $occurredAt): void {
            DB::table('inbox_messages')->insert([
                'message_id' => $messageId,
                'processed_at' => now(),
                'created_at' => now(),
            ]);

            $this->updateDailyAggregate($eventType, $payload, $occurredAt);
        });

        Log::info('Projected refund event', [
            'message_id' => $messageId,
            'event_type' => $eventType,
            'refund_id' => $payload['refund_id'] ?? null,
        ]);
    }

    private function updateDailyAggregate(string $eventType, array $payload, string $occurredAt): void
    {
        if ($eventType !== 'refund.succeeded') {
            return;
        }

        $date = substr($occurredAt, 0, 10);
        $currency = (string) ($payload['amount']['currency'] ?? '');
        $amount = (int) ($payload['amount']['value'] ?? 0);
        $now = now();

        DB::table('daily_aggregates')->insertOrIgnore([
            'date' => $date,
            'currency' => $currency,
            'updated_at' => $now,
        ]);

        DB::table('daily_aggregates')
            ->where('date', $date)
            ->where('currency', $currency)
            ->update([
                'refunds_succeeded' => DB::raw('refunds_succeeded + 1'),
                'refund_volume_cents' => DB::raw("refund_volume_cents + {$amount}"),
                'updated_at' => $now,
            ]);
    }
}
