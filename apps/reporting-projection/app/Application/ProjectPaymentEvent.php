<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Persistence\MerchantPaymentSummary;
use App\Infrastructure\Persistence\PaymentProjection;
use App\Infrastructure\Persistence\ProviderPerformanceSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProjectPaymentEvent
{
    /**
     * Project a payment lifecycle Kafka envelope into all read models.
     *
     * The message_id is used as the inbox deduplication key. Every read-model
     * write is wrapped in a single transaction with the inbox insert so that
     * partial updates are impossible.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function execute(string $messageId, array $envelope): void
    {
        $alreadyProcessed = DB::table('inbox_messages')
            ->where('message_id', $messageId)
            ->exists();

        if ($alreadyProcessed) {
            Log::info('Skipping duplicate payment event', [
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

            $this->updatePaymentProjection($eventType, $payload);
            $this->updateMerchantSummary((string) ($payload['merchant_id'] ?? ''));
            $this->updateProviderSummary($payload['provider_id'] ?? null);
            $this->updateDailyAggregate($eventType, $payload, $occurredAt);
        });

        Log::info('Projected payment event', [
            'message_id' => $messageId,
            'event_type' => $eventType,
            'payment_id' => $payload['payment_id'] ?? null,
        ]);
    }

    private function updatePaymentProjection(string $eventType, array $payload): void
    {
        $paymentId = (string) ($payload['payment_id'] ?? '');
        $occurredAt = $payload['occurred_at'] ?? now()->toIso8601String();

        $timestampField = match ($eventType) {
            'payment.authorized' => ['authorized_at' => $occurredAt],
            'payment.captured' => ['captured_at' => $occurredAt],
            'payment.refunded' => ['refunded_at' => $occurredAt],
            'payment.failed' => ['failed_at' => $occurredAt],
            default => [],
        };

        $fields = array_merge([
            'merchant_id' => $payload['merchant_id'] ?? null,
            'external_reference' => $payload['external_reference'] ?? null,
            'amount' => $payload['amount']['value'] ?? 0,
            'currency' => $payload['amount']['currency'] ?? '',
            'status' => $payload['status'] ?? '',
            'provider_id' => $payload['provider_id'] ?? null,
        ], $timestampField);

        $projection = PaymentProjection::find($paymentId);

        if ($projection === null) {
            PaymentProjection::create(array_merge(['id' => $paymentId], $fields));
        } else {
            // Preserve existing timestamps — only set if not already recorded.
            foreach (['authorized_at', 'captured_at', 'refunded_at', 'failed_at'] as $ts) {
                if ($projection->{$ts} !== null) {
                    unset($fields[$ts]);
                }
            }
            $projection->update($fields);
        }
    }

    /**
     * Recompute the merchant summary directly from payment_projections.
     * This approach is always idempotent regardless of event ordering or replay.
     */
    private function updateMerchantSummary(string $merchantId): void
    {
        if ($merchantId === '') {
            return;
        }

        $stats = DB::table('payment_projections')
            ->where('merchant_id', $merchantId)
            ->selectRaw("
                COUNT(*) as total_count,
                SUM(CASE WHEN status = 'captured' THEN 1 ELSE 0 END) as captured_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                COALESCE(SUM(amount), 0) as total_volume_cents,
                COALESCE(SUM(CASE WHEN status = 'captured' THEN amount ELSE 0 END), 0) as captured_volume_cents,
                COALESCE(SUM(CASE WHEN status = 'refunded' THEN amount ELSE 0 END), 0) as refunded_volume_cents
            ")
            ->first();

        MerchantPaymentSummary::updateOrCreate(
            ['merchant_id' => $merchantId],
            [
                'total_count' => (int) $stats->total_count,
                'captured_count' => (int) $stats->captured_count,
                'failed_count' => (int) $stats->failed_count,
                'refunded_count' => (int) $stats->refunded_count,
                'cancelled_count' => (int) $stats->cancelled_count,
                'total_volume_cents' => (int) $stats->total_volume_cents,
                'captured_volume_cents' => (int) $stats->captured_volume_cents,
                'refunded_volume_cents' => (int) $stats->refunded_volume_cents,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Recompute the provider summary directly from payment_projections.
     * This approach is always idempotent regardless of event ordering or replay.
     */
    private function updateProviderSummary(?string $providerId): void
    {
        if ($providerId === null || $providerId === '') {
            return;
        }

        $stats = DB::table('payment_projections')
            ->where('provider_id', $providerId)
            ->selectRaw("
                COUNT(*) as total_attempts,
                SUM(CASE WHEN status = 'authorized' THEN 1 ELSE 0 END) as authorized_count,
                SUM(CASE WHEN status = 'captured' THEN 1 ELSE 0 END) as captured_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
            ")
            ->first();

        ProviderPerformanceSummary::updateOrCreate(
            ['provider_id' => $providerId],
            [
                'total_attempts' => (int) $stats->total_attempts,
                'authorized_count' => (int) $stats->authorized_count,
                'captured_count' => (int) $stats->captured_count,
                'failed_count' => (int) $stats->failed_count,
                'updated_at' => now(),
            ]
        );
    }

    private function updateDailyAggregate(string $eventType, array $payload, string $occurredAt): void
    {
        $countColumn = match ($eventType) {
            'payment.initiated' => 'payments_initiated',
            'payment.captured' => 'payments_captured',
            'payment.failed' => 'payments_failed',
            'payment.cancelled' => 'payments_cancelled',
            default => null,
        };

        if ($countColumn === null) {
            return;
        }

        $date = substr($occurredAt, 0, 10);
        $currency = (string) ($payload['amount']['currency'] ?? '');
        $amount = (int) ($payload['amount']['value'] ?? 0);
        $now = now();

        $volumeColumn = match ($eventType) {
            'payment.initiated' => 'volume_initiated_cents',
            'payment.captured' => 'volume_captured_cents',
            default => null,
        };

        DB::table('daily_aggregates')->insertOrIgnore([
            'date' => $date,
            'currency' => $currency,
            'updated_at' => $now,
        ]);

        $updates = [
            $countColumn => DB::raw("{$countColumn} + 1"),
            'updated_at' => $now,
        ];

        if ($volumeColumn !== null) {
            $updates[$volumeColumn] = DB::raw("{$volumeColumn} + {$amount}");
        }

        DB::table('daily_aggregates')
            ->where('date', $date)
            ->where('currency', $currency)
            ->update($updates);
    }
}
