<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Outbox;

use App\Infrastructure\Outbox\Publisher\Kafka\EventRouter;
use App\Infrastructure\Outbox\Publisher\UnroutableEventException;
use Tests\TestCase;

class EventRouterTest extends TestCase
{
    private EventRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new EventRouter;
    }

    public function test_ledger_entry_posted_routes_to_kafka_ledger_entries(): void
    {
        $route = $this->router->resolve('ledger.entry_posted.v1');

        $this->assertSame('kafka', $route['broker']);
        $this->assertSame('ledger.entries.v1', $route['destination']);
    }

    public function test_unknown_event_type_throws_unroutable_event_exception(): void
    {
        $this->expectException(UnroutableEventException::class);
        $this->expectExceptionMessage("No route registered for event type 'unknown.event.v1'");

        $this->router->resolve('unknown.event.v1');
    }

    public function test_empty_event_type_throws_unroutable_event_exception(): void
    {
        $this->expectException(UnroutableEventException::class);

        $this->router->resolve('');
    }

    public function test_payment_event_type_is_not_routable_from_ledger_service(): void
    {
        $this->expectException(UnroutableEventException::class);

        $this->router->resolve('payment.initiated.v1');
    }
}
