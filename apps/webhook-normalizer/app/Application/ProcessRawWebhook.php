<?php

namespace App\Application;

use App\Domain\Normalizer\NormalizedWebhookEvent;
use App\Domain\Normalizer\ProviderNormalizerRegistry;
use App\Domain\Normalizer\UnmappableWebhookException;
use App\Domain\Signal\DeadWorkflowException;
use App\Domain\Signal\TemporalSignalDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        if ($normalizedEvent !== null) {
            $this->dispatchSignal($normalizedEvent, $correlationId);
        }

        // TASK-093: publish normalized Kafka event with $normalizedEvent

        $now = now();

        DB::transaction(function () use ($messageId, $now): void {
            DB::table('inbox_messages')->insert([
                'message_id' => $messageId,
                'processed_at' => $now,
                'created_at' => $now,
            ]);
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

    private function dispatchSignal(NormalizedWebhookEvent $event, string $correlationId): void
    {
        try {
            $this->signalDispatcher->dispatch($event, $correlationId);
        } catch (DeadWorkflowException $e) {
            // Workflow is gone — log and continue. TASK-094 handles publishing
            // the WebhookSignalUndeliverable Kafka event.
            Log::warning('Temporal workflow not found for normalized webhook — signal undeliverable', [
                'payment_id' => $event->paymentId,
                'provider_event_id' => $event->providerEventId,
                'provider' => $event->provider,
                'correlation_id' => $correlationId,
            ]);
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
