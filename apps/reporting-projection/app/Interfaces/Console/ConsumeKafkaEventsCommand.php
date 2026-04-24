<?php

declare(strict_types=1);

namespace App\Interfaces\Console;

use App\Application\ProjectPaymentEvent;
use App\Application\ProjectRefundEvent;
use App\Infrastructure\Kafka\KafkaConsumer;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use longlang\phpkafka\Consumer\ConsumeMessage;
use longlang\phpkafka\Exception\KafkaException;

final class ConsumeKafkaEventsCommand extends Command
{
    protected $signature = 'reporting:consume-events
        {--max-messages=0 : Stop after N messages processed (0 = run indefinitely)}';

    protected $description = 'Consume payment and refund lifecycle events from Kafka and update projection read models';

    private bool $shouldStop = false;

    public function handle(ProjectPaymentEvent $paymentProjector, ProjectRefundEvent $refundProjector): int
    {
        $this->info('Kafka event consumer started.');

        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGTERM, function (): void {
                $this->shouldStop = true;
            });
            pcntl_signal(SIGINT, function (): void {
                $this->shouldStop = true;
            });
        }

        $kafkaConfig = config('kafka');
        $consumer = new KafkaConsumer(
            brokers: $kafkaConfig['brokers'],
            groupId: $kafkaConfig['consumer_group_id'],
            clientId: $kafkaConfig['client_id'],
            topics: [
                $kafkaConfig['topics']['payments_lifecycle'],
                $kafkaConfig['topics']['refunds_lifecycle'],
            ],
            autoOffsetReset: $kafkaConfig['auto_offset_reset'],
        );

        $maxMessages = (int) $this->option('max-messages');
        $processed = 0;

        try {
            while (! $this->shouldStop) {
                if (extension_loaded('pcntl')) {
                    pcntl_signal_dispatch();
                }

                $message = $consumer->consume();

                if ($message === null) {
                    continue;
                }

                $this->handleMessage($message, $consumer, $paymentProjector, $refundProjector);
                $processed++;

                if ($maxMessages > 0 && $processed >= $maxMessages) {
                    break;
                }
            }
        } finally {
            $consumer->close();
        }

        $this->info("Kafka event consumer stopped. Processed {$processed} message(s).");

        return Command::SUCCESS;
    }

    private function handleMessage(
        ConsumeMessage $message,
        KafkaConsumer $consumer,
        ProjectPaymentEvent $paymentProjector,
        ProjectRefundEvent $refundProjector,
    ): void {
        $topic = $message->getTopic();
        $value = $message->getValue();

        if ($value === null) {
            $consumer->ack($message);

            return;
        }

        try {
            $envelope = json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('Malformed Kafka message — discarding', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
            $consumer->ack($message);

            return;
        }

        $messageId = (string) ($envelope['message_id'] ?? '');

        if ($messageId === '') {
            Log::warning('Kafka message missing message_id — discarding', ['topic' => $topic]);
            $consumer->ack($message);

            return;
        }

        $paymentsLifecycleTopic = config('kafka.topics.payments_lifecycle');
        $refundsLifecycleTopic = config('kafka.topics.refunds_lifecycle');

        try {
            if ($topic === $paymentsLifecycleTopic) {
                $paymentProjector->execute($messageId, $envelope);
            } elseif ($topic === $refundsLifecycleTopic) {
                $refundProjector->execute($messageId, $envelope);
            } else {
                Log::warning('Received message on unknown topic — discarding', ['topic' => $topic]);
            }

            $consumer->ack($message);
        } catch (QueryException $e) {
            // Transient DB error — do not ack; Kafka will re-deliver after consumer rejoins.
            Log::error('Transient DB error projecting Kafka event — not acking', [
                'message_id' => $messageId,
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (KafkaException $e) {
            Log::error('Kafka error while projecting event — not acking', [
                'message_id' => $messageId,
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Permanent error projecting Kafka event — discarding', [
                'message_id' => $messageId,
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
            $consumer->ack($message);
        }
    }
}
