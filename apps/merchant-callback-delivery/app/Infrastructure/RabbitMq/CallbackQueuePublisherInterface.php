<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

interface CallbackQueuePublisherInterface
{
    /**
     * Publish a JSON-encoded message to the named RabbitMQ queue.
     *
     * @throws CallbackPublishException on permanent broker errors.
     * @throws CallbackTransientException on retriable connection errors.
     */
    public function publish(string $queue, string $messageId, string $body): void;
}
