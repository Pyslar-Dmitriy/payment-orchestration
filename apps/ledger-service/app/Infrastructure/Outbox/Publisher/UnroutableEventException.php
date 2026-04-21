<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox\Publisher;

use RuntimeException;

final class UnroutableEventException extends RuntimeException
{
    public function __construct(string $eventType)
    {
        parent::__construct("No route registered for event type '{$eventType}'");
    }
}
