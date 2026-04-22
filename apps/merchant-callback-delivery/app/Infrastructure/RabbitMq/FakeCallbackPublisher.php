<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

final class FakeCallbackPublisher implements CallbackQueuePublisherInterface
{
    /** @var list<array{queue: string, messageId: string, body: string}> */
    private array $published = [];

    public function publish(string $queue, string $messageId, string $body): void
    {
        $this->published[] = ['queue' => $queue, 'messageId' => $messageId, 'body' => $body];
    }

    /** @return list<array{queue: string, messageId: string, body: string}> */
    public function published(): array
    {
        return $this->published;
    }

    public function reset(): void
    {
        $this->published = [];
    }
}
