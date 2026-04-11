<?php

namespace App\Infrastructure\Outbox;

use App\Infrastructure\Outbox\Publisher\BrokerPublishException;
use App\Infrastructure\Outbox\Publisher\BrokerTransientException;
use App\Infrastructure\Outbox\Publisher\Kafka\EventRouter;
use App\Infrastructure\Outbox\Publisher\Kafka\KafkaBrokerPublisher;
use App\Infrastructure\Outbox\Publisher\Kafka\KafkaEnvelopeBuilder;
use App\Infrastructure\Outbox\Publisher\RabbitMq\RabbitMqBrokerPublisher;
use App\Infrastructure\Outbox\Publisher\UnroutableEventException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OutboxPublisherService
{
    public function __construct(
        private readonly KafkaBrokerPublisher $kafkaPublisher,
        private readonly RabbitMqBrokerPublisher $rabbitMqPublisher,
        private readonly EventRouter $router,
        private readonly KafkaEnvelopeBuilder $envelopeBuilder,
    ) {}

    /**
     * Claim and publish one batch of pending outbox events.
     *
     * Uses SELECT … FOR UPDATE SKIP LOCKED so concurrent publisher processes
     * never attempt to publish the same row — each picks a disjoint set.
     *
     * @return int Number of events successfully published in this batch.
     */
    public function processBatch(): int
    {
        $batchSize = (int) config('outbox.batch_size', 50);
        $maxRetries = (int) config('outbox.max_retries', 5);

        return DB::transaction(function () use ($batchSize, $maxRetries): int {
            // Claim a batch of pending rows inside a transaction so the FOR UPDATE
            // lock is held until each event is processed. SKIP LOCKED lets concurrent
            // publisher processes pick disjoint sets rather than blocking each other.
            $rows = DB::select(
                'SELECT id FROM outbox_events '
                .'WHERE published_at IS NULL AND failed_permanently = FALSE '
                .'ORDER BY created_at ASC '
                .'LIMIT ? '
                .'FOR UPDATE SKIP LOCKED',
                [$batchSize],
            );

            if (empty($rows)) {
                return 0;
            }

            $eventIds = array_column($rows, 'id');
            $events = OutboxEvent::whereIn('id', $eventIds)->orderBy('created_at')->get();

            $published = 0;

            foreach ($events as $event) {
                if ($this->publishEvent($event, $maxRetries)) {
                    $published++;
                }
            }

            return $published;
        });
    }

    private function publishEvent(OutboxEvent $event, int $maxRetries): bool
    {
        try {
            $route = $this->router->resolve($event->event_type);
            $envelope = $this->envelopeBuilder->build($event);
            $body = json_encode($envelope, JSON_THROW_ON_ERROR);
            $headers = [
                'aggregate_id' => $event->aggregate_id,
                'correlation_id' => $envelope['correlation_id'],
                'source_service' => 'payment-domain',
                'event_type' => $envelope['event_type'],
            ];

            $publisher = $route['broker'] === 'kafka'
                ? $this->kafkaPublisher
                : $this->rabbitMqPublisher;

            $publisher->publish($route['destination'], $event->id, $body, $headers);

            $event->update(['published_at' => now()]);

            return true;

        } catch (UnroutableEventException $e) {
            // Programming error — dead-letter immediately, no retry.
            $event->update([
                'failed_permanently' => true,
                'last_error' => $e->getMessage(),
            ]);

            Log::error('outbox.unroutable', [
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'error' => $e->getMessage(),
            ]);

        } catch (BrokerTransientException $e) {
            $newCount = $event->retry_count + 1;
            $permanently = $newCount >= $maxRetries;

            $event->update([
                'retry_count' => $newCount,
                'last_error' => $e->getMessage(),
                'failed_permanently' => $permanently,
            ]);

            Log::warning('outbox.transient_failure', [
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'retry_count' => $newCount,
                'permanent' => $permanently,
                'error' => $e->getMessage(),
            ]);

        } catch (BrokerPublishException $e) {
            // Non-retriable broker error — dead-letter immediately.
            $event->update([
                'failed_permanently' => true,
                'last_error' => $e->getMessage(),
            ]);

            Log::error('outbox.publish_failed', [
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
