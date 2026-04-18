<?php

namespace App\Infrastructure\Queue;

use PhpAmqpLib\Message\AMQPMessage;

interface RabbitMqConsumerContract
{
    /**
     * @param  callable(AMQPMessage): void  $callback
     */
    public function consume(string $queue, callable $callback): void;
}
