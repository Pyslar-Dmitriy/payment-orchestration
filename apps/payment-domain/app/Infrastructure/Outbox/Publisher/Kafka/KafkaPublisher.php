<?php

namespace App\Infrastructure\Outbox\Publisher\Kafka;

use App\Infrastructure\Outbox\Publisher\BrokerPublishException;
use App\Infrastructure\Outbox\Publisher\BrokerTransientException;
use longlang\phpkafka\Exception\KafkaException;
use longlang\phpkafka\Exception\UnsupportedApiKeyException;
use longlang\phpkafka\Exception\UnsupportedApiVersionException;
use longlang\phpkafka\Producer\Producer;
use longlang\phpkafka\Producer\ProducerConfig;

final class KafkaPublisher implements KafkaBrokerPublisher
{
    private ?Producer $producer = null;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function publish(string $destination, string $messageId, string $body, array $headers = []): void
    {
        try {
            // Use aggregate_id from headers as the partition key so all events
            // for a given aggregate land in the same partition (ordering guarantee).
            $key = $headers['aggregate_id'] ?? $messageId;

            $this->producer()->send($destination, $body, $key);
        } catch (UnsupportedApiVersionException|UnsupportedApiKeyException $e) {
            throw new BrokerPublishException(
                "Permanent Kafka failure for topic '$destination': {$e->getMessage()}",
                previous: $e,
            );
        } catch (KafkaException $e) {
            throw new BrokerTransientException(
                "Kafka publish failed for topic '$destination': {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    private function producer(): Producer
    {
        if ($this->producer === null) {
            $config = new ProducerConfig;
            $config->setBrokers($this->config['brokers'] ?? 'kafka:9092');
            $config->setAcks(-1);
            $config->setClientId($this->config['client_id'] ?? 'payment-domain-outbox');
            $this->producer = new Producer($config);
        }

        return $this->producer;
    }
}
