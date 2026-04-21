<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox\Publisher;

interface BrokerPublisherInterface
{
    /**
     * @param  array<string, string>  $headers
     *
     * @throws BrokerTransientException on retriable failures (connection reset, timeout).
     * @throws BrokerPublishException on permanent failures (auth error, schema rejection).
     */
    public function publish(string $destination, string $messageId, string $body, array $headers = []): void;
}
