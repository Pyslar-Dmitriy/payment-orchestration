<?php

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

    public function test_payment_initiated_routes_to_kafka_payments_lifecycle(): void
    {
        $route = $this->router->resolve('payment.initiated.v1');

        $this->assertSame('kafka', $route['broker']);
        $this->assertSame('payments.lifecycle.v1', $route['destination']);
    }

    public function test_payment_authorized_routes_to_kafka_payments_lifecycle(): void
    {
        $route = $this->router->resolve('payment.authorized.v1');

        $this->assertSame('kafka', $route['broker']);
        $this->assertSame('payments.lifecycle.v1', $route['destination']);
    }

    public function test_payment_captured_routes_to_kafka_payments_lifecycle(): void
    {
        $route = $this->router->resolve('payment.captured.v1');

        $this->assertSame('kafka', $route['broker']);
        $this->assertSame('payments.lifecycle.v1', $route['destination']);
    }

    public function test_payment_failed_routes_to_kafka_payments_lifecycle(): void
    {
        $route = $this->router->resolve('payment.failed.v1');

        $this->assertSame('kafka', $route['broker']);
        $this->assertSame('payments.lifecycle.v1', $route['destination']);
    }

    public function test_payment_refunding_routes_to_kafka_payments_lifecycle(): void
    {
        $route = $this->router->resolve('payment.refunding.v1');

        $this->assertSame('kafka', $route['broker']);
        $this->assertSame('payments.lifecycle.v1', $route['destination']);
    }

    public function test_payment_refunded_routes_to_kafka_payments_lifecycle(): void
    {
        $route = $this->router->resolve('payment.refunded.v1');

        $this->assertSame('kafka', $route['broker']);
        $this->assertSame('payments.lifecycle.v1', $route['destination']);
    }

    public function test_payment_cancelled_routes_to_kafka_payments_lifecycle(): void
    {
        $route = $this->router->resolve('payment.cancelled.v1');

        $this->assertSame('kafka', $route['broker']);
        $this->assertSame('payments.lifecycle.v1', $route['destination']);
    }

    public function test_refund_initiated_routes_to_kafka_refunds_lifecycle(): void
    {
        $route = $this->router->resolve('refund.initiated.v1');

        $this->assertSame('kafka', $route['broker']);
        $this->assertSame('refunds.lifecycle.v1', $route['destination']);
    }

    public function test_unknown_event_type_throws_unroutable_event_exception(): void
    {
        $this->expectException(UnroutableEventException::class);
        $this->expectExceptionMessage('No broker route registered for event type: unknown.event.v1');

        $this->router->resolve('unknown.event.v1');
    }

    public function test_empty_event_type_throws_unroutable_event_exception(): void
    {
        $this->expectException(UnroutableEventException::class);

        $this->router->resolve('');
    }
}
