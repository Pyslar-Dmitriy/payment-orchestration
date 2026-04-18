<?php

namespace App\Infrastructure\Queue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Message\AMQPMessage;

final class RabbitMqConsumer implements RabbitMqConsumerContract
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    /**
     * @param  callable(AMQPMessage): void  $callback
     */
    public function consume(string $queue, callable $callback): void
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
            );

            $channel->basic_qos(prefetch_size: 0, prefetch_count: 1, a_global: false);

            $channel->basic_consume(
                queue: $queue,
                consumer_tag: '',
                no_local: false,
                no_ack: false,
                exclusive: false,
                nowait: false,
                callback: $callback,
            );

            while ($channel->is_consuming()) {
                $channel->wait();
            }
        } catch (AMQPConnectionClosedException|AMQPIOException $e) {
            throw new BrokerTransientException(
                "RabbitMQ connection failed for queue '{$queue}': {$e->getMessage()}",
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
}
