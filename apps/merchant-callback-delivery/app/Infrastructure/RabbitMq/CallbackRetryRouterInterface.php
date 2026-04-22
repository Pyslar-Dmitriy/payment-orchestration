<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMq;

interface CallbackRetryRouterInterface
{
    /**
     * Route the message to the appropriate retry queue based on next attempt number.
     * The retry queue is a delay bucket: messages expire via TTL and are re-routed
     * to the dispatch queue by RabbitMQ's dead-letter exchange mechanism.
     */
    public function routeToRetry(string $messageId, string $body, int $nextAttemptNumber): void;

    /**
     * Route the message to the dead-letter queue for manual inspection and replay.
     */
    public function routeToDlq(string $messageId, string $body): void;
}
