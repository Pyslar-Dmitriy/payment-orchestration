<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use App\Infrastructure\Outbox\Publisher\BrokerPublisherInterface;
use App\Infrastructure\Outbox\Publisher\BrokerPublishException;
use App\Infrastructure\Outbox\Publisher\BrokerTransientException;
use App\Infrastructure\Outbox\Publisher\Kafka\EventRouter;
use App\Infrastructure\Outbox\Publisher\Kafka\KafkaEnvelopeBuilder;
use App\Infrastructure\Outbox\Publisher\UnroutableEventException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OutboxPublisherService
{
    public function __construct(
        private readonly BrokerPublisherInterface $broker,
        private readonly EventRouter $router,
        private readonly KafkaEnvelopeBuilder $envelopeBuilder,
    ) {}

    /**
     * Claim and publish one batch of pending outbox messages.
     *
     * The SELECT … FOR UPDATE SKIP LOCKED transaction is committed before any
     * Kafka I/O, so DB row locks are held only during the brief claim window,
     * not for the duration of network calls to the broker.
     *
     * @return int Number of messages successfully published in this batch.
     */
    public function processBatch(): int
    {
        $batchSize = (int) config('outbox.batch_size', 50);
        $maxRetries = (int) config('outbox.max_retries', 5);

        // Short transaction: claim rows and load into memory. Lock released on commit.
        $messages = DB::transaction(function () use ($batchSize) {
            $rows = DB::select(
                'SELECT id FROM outbox_messages '
                .'WHERE published_at IS NULL AND failed_permanently = FALSE '
                .'ORDER BY created_at ASC '
                .'LIMIT ? '
                .'FOR UPDATE SKIP LOCKED',
                [$batchSize],
            );

            if (empty($rows)) {
                return collect();
            }

            $messageIds = array_column($rows, 'id');

            return OutboxMessage::whereIn('id', $messageIds)->orderBy('created_at')->get();
        });

        if ($messages->isEmpty()) {
            return 0;
        }

        $published = 0;

        foreach ($messages as $message) {
            if ($this->publishMessage($message, $maxRetries)) {
                $published++;
            }
        }

        return $published;
    }

    private function publishMessage(OutboxMessage $message, int $maxRetries): bool
    {
        try {
            $route = $this->router->resolve($message->event_type);
            $envelope = $this->envelopeBuilder->build($message);
            $body = json_encode($envelope, JSON_THROW_ON_ERROR);
            $headers = [
                'aggregate_id' => $message->aggregate_id,
                'correlation_id' => $envelope['correlation_id'],
                'source_service' => 'ledger-service',
                'event_type' => $envelope['event_type'],
            ];

            $this->broker->publish($route['destination'], $message->id, $body, $headers);

            $message->update(['published_at' => now()]);

            return true;

        } catch (UnroutableEventException $e) {
            $message->update([
                'failed_permanently' => true,
                'last_error' => $e->getMessage(),
            ]);

            Log::error('outbox.unroutable', [
                'message_id' => $message->id,
                'event_type' => $message->event_type,
                'error' => $e->getMessage(),
            ]);

        } catch (BrokerTransientException $e) {
            $newCount = $message->retry_count + 1;
            $permanently = $newCount >= $maxRetries;

            $message->update([
                'retry_count' => $newCount,
                'last_error' => $e->getMessage(),
                'failed_permanently' => $permanently,
            ]);

            Log::warning('outbox.transient_failure', [
                'message_id' => $message->id,
                'event_type' => $message->event_type,
                'retry_count' => $newCount,
                'permanent' => $permanently,
                'error' => $e->getMessage(),
            ]);

        } catch (BrokerPublishException $e) {
            $message->update([
                'failed_permanently' => true,
                'last_error' => $e->getMessage(),
            ]);

            Log::error('outbox.publish_failed', [
                'message_id' => $message->id,
                'event_type' => $message->event_type,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
