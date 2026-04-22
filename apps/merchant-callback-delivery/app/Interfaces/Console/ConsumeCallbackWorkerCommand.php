<?php

declare(strict_types=1);

namespace App\Interfaces\Console;

use App\Application\DeliverCallback\DeliverCallbackCommand;
use App\Application\DeliverCallback\DeliverCallbackHandler;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

final class ConsumeCallbackWorkerCommand extends Command
{
    protected $signature = 'callback:work';

    protected $description = 'Consume merchant callback dispatch queue and deliver HTTP callbacks with retry/backoff/DLQ';

    public function handle(DeliverCallbackHandler $handler): int
    {
        $config = (array) config('rabbitmq');
        $queue = (string) ($config['queues']['dispatch'] ?? 'merchant.callback.dispatch');

        $this->info("Starting callback worker. Consuming from [{$queue}].");

        $connection = new AMQPStreamConnection(
            host: $config['host'] ?? 'rabbitmq',
            port: (int) ($config['port'] ?? 5672),
            user: $config['user'] ?? 'guest',
            password: $config['password'] ?? 'guest',
            vhost: $config['vhost'] ?? '/',
        );

        $channel = $connection->channel();

        $channel->queue_declare(
            queue: $queue,
            passive: false,
            durable: true,
            exclusive: false,
            auto_delete: false,
        );

        $channel->basic_qos(prefetch_size: 0, prefetch_count: 1, global: false);

        $channel->basic_consume(
            queue: $queue,
            consumer_tag: '',
            no_local: false,
            no_ack: false,
            exclusive: false,
            nowait: false,
            callback: function (AMQPMessage $msg) use ($handler): void {
                $this->processMessage($msg, $handler);
            },
        );

        $this->info('Worker ready. Waiting for messages...');

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();

        return self::SUCCESS;
    }

    private function processMessage(AMQPMessage $msg, DeliverCallbackHandler $handler): void
    {
        try {
            $data = json_decode($msg->body, true, flags: JSON_THROW_ON_ERROR);
            $command = DeliverCallbackCommand::fromArray($data);
            $handler->handle($command);
            $msg->ack();
        } catch (\JsonException|\InvalidArgumentException $e) {
            // Malformed or structurally invalid message — cannot retry, nack without requeue
            $this->error("Malformed message: {$e->getMessage()}");
            $msg->nack(requeue: false);
        } catch (\Throwable $e) {
            // Unexpected processing error — requeue for another attempt
            $this->error("Error processing message: {$e->getMessage()}");
            $msg->nack(requeue: true);
        }
    }
}
