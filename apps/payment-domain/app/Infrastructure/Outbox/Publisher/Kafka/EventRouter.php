<?php

namespace App\Infrastructure\Outbox\Publisher\Kafka;

use App\Infrastructure\Outbox\Publisher\UnroutableEventException;

final class EventRouter
{
    /**
     * Maps each known event_type to [broker, destination].
     * Kafka topics align with the contracts defined in TASK-031.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ROUTES = [
        // Payment lifecycle → payments.lifecycle.v1
        'payment.initiated.v1' => ['kafka', 'payments.lifecycle.v1'],
        'payment.pending_provider.v1' => ['kafka', 'payments.lifecycle.v1'],
        'payment.authorized.v1' => ['kafka', 'payments.lifecycle.v1'],
        'payment.captured.v1' => ['kafka', 'payments.lifecycle.v1'],
        'payment.failed.v1' => ['kafka', 'payments.lifecycle.v1'],
        'payment.refunding.v1' => ['kafka', 'payments.lifecycle.v1'],
        'payment.refunded.v1' => ['kafka', 'payments.lifecycle.v1'],
        'payment.cancelled.v1' => ['kafka', 'payments.lifecycle.v1'],
        // Refund lifecycle → refunds.lifecycle.v1
        'refund.initiated.v1' => ['kafka', 'refunds.lifecycle.v1'],
    ];

    /**
     * @return array{broker: string, destination: string}
     *
     * @throws UnroutableEventException if the event type has no registered route.
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
