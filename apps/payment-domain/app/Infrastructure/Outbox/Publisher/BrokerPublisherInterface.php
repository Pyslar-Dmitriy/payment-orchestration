<?php

namespace App\Infrastructure\Outbox\Publisher;

interface BrokerPublisherInterface
{
    /**
     * Publish a serialised message to the named topic or queue.
     *
     * @param  string  $destination  Kafka topic name or RabbitMQ queue/exchange name.
     * @param  string  $messageId  Unique message ID used by consumers for inbox deduplication.
     * @param  string  $body  JSON-encoded message envelope.
     * @param  array<string, string>  $headers  Optional broker-level headers.
     *
     * @throws BrokerTransientException on retriable failures (connection reset, timeout).
     * @throws BrokerPublishException on permanent failures (auth error, schema rejection).
     */
    public function publish(string $destination, string $messageId, string $body, array $headers = []): void;
}
