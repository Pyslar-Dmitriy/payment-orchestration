<?php

namespace App\Infrastructure\Outbox\Publisher\Kafka;

use App\Infrastructure\Outbox\Publisher\BrokerTransientException;
use longlang\phpkafka\Exception\KafkaException;
use longlang\phpkafka\Producer\Producer;
use longlang\phpkafka\Producer\ProducerConfig;

final class KafkaPublisher implements KafkaBrokerPublisher
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function publish(string $destination, string $messageId, string $body, array $headers = []): void
    {
        try {
            $producerConfig = new ProducerConfig;
            $producerConfig->setBrokers($this->config['brokers'] ?? 'kafka:9092');
            $producerConfig->setAcks(-1); // wait for all in-sync replicas
            $producerConfig->setClientId($this->config['client_id'] ?? 'payment-domain-outbox');

            $producer = new Producer($producerConfig);

            // Use aggregate_id from headers as the partition key so all events
            // for a given aggregate land in the same partition (ordering guarantee).
            $key = $headers['aggregate_id'] ?? $messageId;

            $producer->send($destination, $body, $key);
            $producer->close();
        } catch (KafkaException $e) {
            throw new BrokerTransientException(
                "Kafka publish failed for topic '$destination': {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
