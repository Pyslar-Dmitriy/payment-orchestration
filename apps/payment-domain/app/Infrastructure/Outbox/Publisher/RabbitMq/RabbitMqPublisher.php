<?php

namespace App\Infrastructure\Outbox\Publisher\RabbitMq;

use App\Infrastructure\Outbox\Publisher\BrokerPublishException;
use App\Infrastructure\Outbox\Publisher\BrokerTransientException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Message\AMQPMessage;

final class RabbitMqPublisher implements RabbitMqBrokerPublisher
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function publish(string $destination, string $messageId, string $body, array $headers = []): void
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
                queue: $destination,
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

            $channel->basic_publish(msg: $message, routing_key: $destination);

            $channel->close();
            $connection->close();
        } catch (AMQPConnectionClosedException|AMQPIOException $e) {
            throw new BrokerTransientException(
                "RabbitMQ publish failed for queue '{$destination}': {$e->getMessage()}",
                previous: $e,
            );
        } catch (\Exception $e) {
            throw new BrokerPublishException(
                "RabbitMQ permanent failure for queue '{$destination}': {$e->getMessage()}",
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
