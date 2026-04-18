<?php

namespace App\Interfaces\Console;

use App\Application\ProcessRawWebhook;
use App\Infrastructure\Queue\BrokerTransientException;
use App\Infrastructure\Queue\RabbitMqConsumerContract;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

final class ConsumeRawWebhookCommand extends Command
{
    protected $signature = 'webhook:consume';

    protected $description = 'Consume raw webhook events from the provider.webhook.raw queue';

    public function handle(RabbitMqConsumerContract $consumer, ProcessRawWebhook $processor): int
    {
        $this->info('Raw webhook consumer started.');

        $consumer->consume(
            queue: 'provider.webhook.raw',
            callback: function (AMQPMessage $message) use ($processor): void {
                $this->handleMessage($message, $processor);
            },
        );

        return Command::SUCCESS;
    }

    private function handleMessage(AMQPMessage $message, ProcessRawWebhook $processor): void
    {
        $messageId = $message->get_properties()['message_id'] ?? null;

        try {
            $payload = json_decode($message->body, associative: true, flags: JSON_THROW_ON_ERROR);

            if (
                $messageId === null
                || ! isset($payload['raw_event_id'], $payload['provider'], $payload['event_id'])
            ) {
                Log::warning('Malformed raw webhook message — discarding', [
                    'message_id' => $messageId,
                    'body' => $message->body,
                ]);
                $message->nack(requeue: false);

                return;
            }

            $processor->execute($messageId, $payload);
            $message->ack();
        } catch (QueryException $e) {
            Log::error('Transient DB error processing raw webhook — requeueing', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            $message->nack(requeue: true);
        } catch (BrokerTransientException $e) {
            Log::error('Transient broker error processing raw webhook — requeueing', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            $message->nack(requeue: true);
        } catch (\Throwable $e) {
            Log::error('Permanent error processing raw webhook — discarding', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            $message->nack(requeue: false);
        }
    }
}
