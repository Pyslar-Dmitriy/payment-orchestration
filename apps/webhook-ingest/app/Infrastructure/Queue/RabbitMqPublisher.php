<?php

namespace App\Infrastructure\Queue;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Message\AMQPMessage;

final class RabbitMqPublisher implements RabbitMqPublisherContract
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function publish(string $queue, string $messageId, string $body): void
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

            $message = new AMQPMessage(
                body: $body,
                properties: [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'message_id' => $messageId,
                ],
            );

            $channel->basic_publish(msg: $message, routing_key: $queue);
        } catch (AMQPConnectionClosedException|AMQPIOException $e) {
            throw new BrokerTransientException(
                "RabbitMQ publish failed for queue '{$queue}': {$e->getMessage()}",
                previous: $e,
            );
        } catch (\Exception $e) {
            throw new BrokerPublishException(
                "RabbitMQ permanent failure for queue '{$queue}': {$e->getMessage()}",
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
