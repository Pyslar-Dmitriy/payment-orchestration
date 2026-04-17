<?php

namespace App\Infrastructure\Queue;

interface RabbitMqPublisherContract
{
    public function publish(string $queue, string $messageId, string $body): void;
}
