<?php

declare(strict_types=1);

namespace App\Infrastructure\Kafka;

use longlang\phpkafka\Consumer\ConsumeMessage;
use longlang\phpkafka\Consumer\Consumer;
use longlang\phpkafka\Consumer\ConsumerConfig;

final class KafkaConsumer
{
    private Consumer $consumer;

    /** @param string[] $topics */
    public function __construct(
        string $brokers,
        string $groupId,
        string $clientId,
        array $topics,
        string $autoOffsetReset = 'earliest',
    ) {
        $config = new ConsumerConfig;
        $config->setBrokers($brokers);
        $config->setGroupId($groupId);
        $config->setClientId($clientId);
        $config->setTopic($topics);
        $config->setAutoOffsetReset($autoOffsetReset);
        $config->setAutoCommit(false);
        $config->setInterval(0.1);

        $this->consumer = new Consumer($config);
    }

    public function consume(): ?ConsumeMessage
    {
        return $this->consumer->consume();
    }

    public function ack(ConsumeMessage $message): void
    {
        $this->consumer->ack($message);
    }

    public function close(): void
    {
        $this->consumer->close();
    }
}
