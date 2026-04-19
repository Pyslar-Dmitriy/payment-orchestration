<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox\Publisher;

use longlang\phpkafka\Exception\KafkaException;
use longlang\phpkafka\Exception\UnsupportedApiKeyException;
use longlang\phpkafka\Exception\UnsupportedApiVersionException;
use longlang\phpkafka\Exception\UnsupportedCompressionException;
use longlang\phpkafka\Producer\Producer;
use longlang\phpkafka\Producer\ProducerConfig;

final class KafkaPublisher implements BrokerPublisherInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function publish(string $destination, string $messageId, string $body, array $headers = []): void
    {
        try {
            $producerConfig = new ProducerConfig;
            $producerConfig->setBrokers($this->config['brokers'] ?? 'kafka:9092');
            $producerConfig->setAcks(-1);
            $producerConfig->setClientId($this->config['client_id'] ?? 'webhook-normalizer-outbox');

            $producer = new Producer($producerConfig);

            // Partition key: provider_event_id from headers ensures all events for
            // the same provider event land in the same partition (ordering guarantee).
            $key = $headers['aggregate_id'] ?? $messageId;

            $producer->send($destination, $body, $key);
            $producer->close();
        } catch (UnsupportedApiKeyException|UnsupportedApiVersionException|UnsupportedCompressionException $e) {
            // Permanent misconfiguration — retrying will never succeed.
            throw new BrokerPublishException(
                "Permanent Kafka error for topic '$destination': {$e->getMessage()}",
                previous: $e,
            );
        } catch (KafkaException $e) {
            throw new BrokerTransientException(
                "Kafka publish failed for topic '$destination': {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
