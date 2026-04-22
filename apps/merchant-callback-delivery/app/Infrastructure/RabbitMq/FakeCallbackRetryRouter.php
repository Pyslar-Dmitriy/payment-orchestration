<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

final class FakeCallbackRetryRouter implements CallbackRetryRouterInterface
{
    /** @var list<array{messageId: string, body: string, nextAttemptNumber: int}> */
    private array $retried = [];

    /** @var list<array{messageId: string, body: string}> */
    private array $dlq = [];

    public function routeToRetry(string $messageId, string $body, int $nextAttemptNumber): void
    {
        $this->retried[] = ['messageId' => $messageId, 'body' => $body, 'nextAttemptNumber' => $nextAttemptNumber];
    }

    public function routeToDlq(string $messageId, string $body): void
    {
        $this->dlq[] = ['messageId' => $messageId, 'body' => $body];
    }

    /** @return list<array{messageId: string, body: string, nextAttemptNumber: int}> */
    public function retried(): array
    {
        return $this->retried;
    }

    /** @return list<array{messageId: string, body: string}> */
    public function dlq(): array
    {
        return $this->dlq;
    }

    public function reset(): void
    {
        $this->retried = [];
        $this->dlq = [];
    }
}
