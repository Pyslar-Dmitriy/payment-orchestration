<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox\Publisher\Kafka;

use App\Infrastructure\Outbox\Publisher\UnroutableEventException;

final class EventRouter
{
    private const ROUTES = [
        'ledger.entry_posted.v1' => ['kafka', 'ledger.entries.v1'],
    ];

    /**
     * @return array{broker: string, destination: string}
     *
     * @throws UnroutableEventException
     */
    public function resolve(string $eventType): array
    {
        if (! isset(self::ROUTES[$eventType])) {
            throw new UnroutableEventException($eventType);
        }

        [$broker, $destination] = self::ROUTES[$eventType];

        return ['broker' => $broker, 'destination' => $destination];
    }
}
