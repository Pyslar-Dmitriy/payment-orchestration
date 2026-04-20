<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Normalizer\NormalizedWebhookEvent;
use App\Domain\Normalizer\ProviderNormalizerRegistry;
use App\Domain\Normalizer\UnmappableWebhookException;
use App\Domain\Signal\DeadWorkflowException;
use App\Domain\Signal\TemporalSignalDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ProcessRawWebhook
{
    public function __construct(
        private readonly ProviderNormalizerRegistry $normalizerRegistry,
        private readonly TemporalSignalDispatcherInterface $signalDispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(string $messageId, array $payload): void
    {
        $alreadyProcessed = DB::table('inbox_messages')
            ->where('message_id', $messageId)
            ->exists();

        if ($alreadyProcessed) {
            Log::info('Duplicate raw webhook message — skipping', [
                'message_id' => $messageId,
            ]);

            return;
        }

        $rawEventId = (string) ($payload['raw_event_id'] ?? '');
        $provider = (string) ($payload['provider'] ?? '');
        $eventId = (string) ($payload['event_id'] ?? '');
        $correlationId = (string) ($payload['correlation_id'] ?? '');

        $normalizedEvent = $this->tryNormalize($provider, $payload);

        // Signal Temporal before committing inbox. If this throws a transient error
        // the exception propagates, the message is requeued, and the inbox row is
        // never inserted — guaranteeing at-least-once delivery without lost signals.
        $deadReason = null;
        if ($normalizedEvent !== null) {
            $deadReason = $this->dispatchSignal($normalizedEvent, $correlationId);
        }

        $now = now();
        $signalId = (string) Str::uuid();

        DB::transaction(function () use ($messageId, $now, $signalId, $normalizedEvent, $rawEventId, $correlationId, $deadReason): void {
            DB::table('inbox_messages')->insert([
                'message_id' => $messageId,
                'processed_at' => $now,
                'created_at' => $now,
            ]);

            if ($normalizedEvent !== null) {
                DB::table('outbox_events')->insert([
                    'id' => $signalId,
                    'aggregate_type' => 'normalized_webhook_event',
                    'aggregate_id' => $normalizedEvent->providerEventId,
                    'event_type' => 'provider.webhook_signal_received.v1',
                    'payload' => json_encode([
                        'correlation_id' => $correlationId,
                        'occurred_at' => $now->toIso8601String(),
                        'signal_id' => $signalId,
                        'raw_event_id' => $rawEventId,
                        'provider' => $normalizedEvent->provider,
                        'provider_event_id' => $normalizedEvent->providerEventId,
                        'signal_type' => str_replace('.', '_', $normalizedEvent->eventType),
                        'payment_id' => $normalizedEvent->paymentId !== '' ? $normalizedEvent->paymentId : null,
                        'provider_reference' => $normalizedEvent->providerReference,
                        'normalized_at' => $now->toIso8601String(),
                    ]),
                    'created_at' => $now,
                ]);

                if ($deadReason !== null) {
                    DB::table('outbox_events')->insert([
                        'id' => (string) Str::uuid(),
                        'aggregate_type' => 'webhook_signal_undeliverable',
                        'aggregate_id' => $normalizedEvent->paymentId,
                        'event_type' => 'webhook.signal.undeliverable.v1',
                        'payload' => json_encode([
                            'payment_id' => $normalizedEvent->paymentId !== '' ? $normalizedEvent->paymentId : null,
                            'correlation_id' => $correlationId,
                            'normalized_status' => $normalizedEvent->internalStatus,
                            'reason' => $deadReason,
                            'provider_event_id' => $normalizedEvent->providerEventId,
                            'occurred_at' => $now->toIso8601String(),
                        ]),
                        'created_at' => $now,
                    ]);
                }
            }
        });

        Log::info('Raw webhook processed by normalizer', [
            'message_id' => $messageId,
            'raw_event_id' => $rawEventId,
            'provider' => $provider,
            'event_id' => $eventId,
            'correlation_id' => $correlationId,
            'internal_status' => $normalizedEvent?->internalStatus,
        ]);
    }

    private function dispatchSignal(NormalizedWebhookEvent $event, string $correlationId): ?string
    {
        try {
            $this->signalDispatcher->dispatch($event, $correlationId);

            return null;
        } catch (DeadWorkflowException $e) {
            Log::warning('Temporal workflow signal undeliverable — workflow dead', [
                'payment_id' => $event->paymentId,
                'correlation_id' => $correlationId,
                'signal_type' => str_replace('.', '_', $event->eventType),
                'provider_event_id' => $event->providerEventId,
                'reason' => $e->getDeadReason(),
            ]);

            return $e->getDeadReason();
        }
        // Transient RuntimeExceptions propagate — message will be requeued.
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function tryNormalize(string $provider, array $rawPayload): ?NormalizedWebhookEvent
    {
        if ($provider === '') {
            return null;
        }

        try {
            return $this->normalizerRegistry->normalize($provider, $rawPayload);
        } catch (UnmappableWebhookException $e) {
            Log::warning('Could not normalize webhook payload', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
