<?php

namespace App\Application;

use App\Application\Exceptions\InvalidWebhookSignatureException;
use App\Application\Exceptions\MissingEventIdException;
use App\Domain\Webhook\SignatureVerifier;
use App\Infrastructure\Queue\PublishRawWebhookJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IngestWebhook
{
    public function __construct(private readonly SignatureVerifier $signatureVerifier) {}

    /**
     * @param  array<string, mixed>  $providerConfig
     * @param  array<string, string>  $headers  Normalised lowercase header map.
     *
     * @throws MissingEventIdException
     * @throws InvalidWebhookSignatureException
     */
    public function execute(
        string $provider,
        array $providerConfig,
        string $rawBody,
        array $headers,
        ?string $correlationId,
    ): void {
        $eventIdHeader = strtolower($providerConfig['event_id_header'] ?? 'x-event-id');
        $eventId = $headers[$eventIdHeader] ?? null;

        if ($eventId === null || $eventId === '') {
            throw new MissingEventIdException("Event-ID header '{$eventIdHeader}' is missing.");
        }

        $signingSecret = $providerConfig['signing_secret'] ?? '';
        $signatureVerified = false;

        if ($signingSecret !== '') {
            $signatureHeader = strtolower($providerConfig['signature_header'] ?? 'x-webhook-signature');
            $signature = $headers[$signatureHeader] ?? null;

            if ($signature === null || $signature === '') {
                throw new InvalidWebhookSignatureException('Webhook signature header is missing.');
            }

            if (! $this->signatureVerifier->verify($rawBody, $signature, $signingSecret)) {
                throw new InvalidWebhookSignatureException('Webhook signature is invalid.');
            }

            $signatureVerified = true;
        }

        $id = Str::uuid()->toString();
        $now = now();

        $inserted = DB::transaction(function () use ($id, $provider, $eventId, $headers, $rawBody, $signatureVerified, $correlationId, $now): int {
            $deduped = DB::table('webhook_dedup')->insertOrIgnore([
                'id' => Str::uuid()->toString(),
                'provider' => $provider,
                'event_id' => $eventId,
                'raw_event_id' => $id,
                'created_at' => $now,
            ]);

            if ($deduped === 0) {
                return 0;
            }

            DB::table('webhook_events_raw')->insert([
                'id' => $id,
                'provider' => $provider,
                'event_id' => $eventId,
                'headers' => json_encode($headers),
                'payload' => $rawBody,
                'signature_verified' => $signatureVerified,
                'correlation_id' => $correlationId,
                'processing_state' => 'received',
                'received_at' => $now,
            ]);

            return 1;
        });

        if ($inserted === 0) {
            Log::info('Duplicate webhook received — skipping', [
                'provider' => $provider,
                'event_id' => $eventId,
            ]);

            return;
        }

        dispatch(new PublishRawWebhookJob($id));

        DB::transaction(function () use ($id, $now): void {
            DB::table('webhook_events_raw')->where('id', $id)->update(['processing_state' => 'enqueued']);

            DB::table('webhook_processing_attempts')->insert([
                'id' => Str::uuid()->toString(),
                'raw_event_id' => $id,
                'state' => 'enqueued',
                'attempt_number' => 1,
                'created_at' => $now,
            ]);
        });

        Log::info('Webhook ingested', [
            'provider' => $provider,
            'event_id' => $eventId,
            'raw_webhook_id' => $id,
        ]);
    }
}
