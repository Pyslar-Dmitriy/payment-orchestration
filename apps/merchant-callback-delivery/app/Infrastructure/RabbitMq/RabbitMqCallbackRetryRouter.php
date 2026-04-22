<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

final class RabbitMqCallbackRetryRouter implements CallbackRetryRouterInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function routeToRetry(string $messageId, string $body, int $nextAttemptNumber): void
    {
        $retryQueue = $this->retryQueueForAttempt($nextAttemptNumber);
        $ttlMs = $this->ttlForQueue($retryQueue);
        $dispatchQueue = (string) ($this->config['queues']['dispatch'] ?? 'merchant.callback.dispatch');

        $this->publish($retryQueue, $messageId, $body, [
            'x-message-ttl' => $ttlMs,
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => $dispatchQueue,
        ]);
    }

    public function routeToDlq(string $messageId, string $body): void
    {
        $dlqQueue = (string) ($this->config['queues']['dlq'] ?? 'merchant.callback.dlq');
        $this->publish($dlqQueue, $messageId, $body, []);
    }

    /** @param array<string, mixed> $queueArguments */
    private function publish(string $queue, string $messageId, string $body, array $queueArguments): void
    {
        $connection = null;
        $channel = null;

        try {
            $connection = new AMQPStreamConnection(
                host: $this->config['host'] ?? 'rabbitmq',
                port: (int) ($this->config['port'] ?? 5672),
                user: $this->config['user'] ?? 'guest',
                password: $this->config['password'] ?? 'guest',
                vhost: $this->config['vhost'] ?? '/',
            );

            /** @var AMQPChannel $channel */
            $channel = $connection->channel();

            $channel->queue_declare(
                queue: $queue,
                passive: false,
                durable: true,
                exclusive: false,
                auto_delete: false,
                nowait: false,
                arguments: $queueArguments !== [] ? new AMQPTable($queueArguments) : null,
            );

            $message = new AMQPMessage(
                body: $body,
                properties: [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'message_id' => $messageId,
                ],
            );

            $channel->basic_publish(msg: $message, routing_key: $queue);

            $channel->close();
            $connection->close();
        } catch (AMQPConnectionClosedException|AMQPIOException $e) {
            throw new CallbackTransientException(
                "RabbitMQ retry route failed for queue '{$queue}': {$e->getMessage()}",
                previous: $e,
            );
        } catch (\Exception $e) {
            throw new CallbackPublishException(
                "RabbitMQ permanent failure routing to queue '{$queue}': {$e->getMessage()}",
                previous: $e,
            );
        } finally {
            try {
                $channel?->close();
            } catch (\Exception) {
            }
            try {
                $connection?->close();
            } catch (\Exception) {
            }
        }
    }

    private function retryQueueForAttempt(int $nextAttemptNumber): string
    {
        $queues = $this->config['queues'] ?? [];

        return match (true) {
            $nextAttemptNumber <= 2 => (string) ($queues['retry_5s'] ?? 'merchant.callback.retry.5s'),
            $nextAttemptNumber === 3 => (string) ($queues['retry_30s'] ?? 'merchant.callback.retry.30s'),
            default => (string) ($queues['retry_5m'] ?? 'merchant.callback.retry.5m'),
        };
    }

    private function ttlForQueue(string $queue): int
    {
        $queues = $this->config['queues'] ?? [];

        return match ($queue) {
            (string) ($queues['retry_5s'] ?? 'merchant.callback.retry.5s') => 5_000,
            (string) ($queues['retry_30s'] ?? 'merchant.callback.retry.30s') => 30_000,
            default => 300_000, // 5 minutes
        };
    }
}
