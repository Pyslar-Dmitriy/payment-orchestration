<?php

namespace App\Infrastructure\Outbox\Publisher;

use RuntimeException;

/**
 * Thrown when an outbox event's event_type has no registered route in EventRouter.
 * This is a programming error — the event is dead-lettered immediately without retrying.
 */
final class UnroutableEventException extends RuntimeException
{
    public function __construct(string $eventType)
    {
        parent::__construct("No broker route registered for event type: {$eventType}");
    }
}
