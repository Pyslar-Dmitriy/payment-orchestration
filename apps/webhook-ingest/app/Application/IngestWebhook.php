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

        // insertOrIgnore translates to INSERT … ON CONFLICT DO NOTHING, which
        // returns 0 for duplicates without raising an exception — safe under
        // any outer database transaction (e.g. RefreshDatabase in tests).
        $inserted = DB::table('raw_webhooks')->insertOrIgnore([
            'id' => $id,
            'provider' => $provider,
            'event_id' => $eventId,
            'headers' => json_encode($headers),
            'payload' => $rawBody,
            'signature_verified' => $signatureVerified,
            'correlation_id' => $correlationId,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            Log::info('Duplicate webhook received — skipping', [
                'provider' => $provider,
                'event_id' => $eventId,
            ]);

            return;
        }

        dispatch(new PublishRawWebhookJob($id));

        Log::info('Webhook ingested', [
            'provider' => $provider,
            'event_id' => $eventId,
            'raw_webhook_id' => $id,
        ]);
    }
}
