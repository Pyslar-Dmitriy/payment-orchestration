<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\DeliverCallback\DeliverCallbackCommand;
use App\Application\DeliverCallback\DeliverCallbackHandler;
use App\Domain\Callback\CallbackAttempt;
use App\Domain\Callback\CallbackDelivery;
use App\Domain\Callback\CallbackSubscription;
use App\Domain\Callback\DeliveryStatus;
use App\Domain\Callback\FailureReason;
use App\Infrastructure\Http\FakeHttpCallbackSender;
use App\Infrastructure\Http\HttpCallbackSenderInterface;
use App\Infrastructure\RabbitMq\CallbackRetryRouterInterface;
use App\Infrastructure\RabbitMq\FakeCallbackRetryRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliverCallbackHandlerTest extends TestCase
{
    use RefreshDatabase;

    private FakeHttpCallbackSender $sender;

    private FakeCallbackRetryRouter $retryRouter;

    private DeliverCallbackHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = new FakeHttpCallbackSender;
        $this->retryRouter = new FakeCallbackRetryRouter;

        $this->app->instance(HttpCallbackSenderInterface::class, $this->sender);
        $this->app->instance(CallbackRetryRouterInterface::class, $this->retryRouter);

        $this->handler = new DeliverCallbackHandler($this->sender, $this->retryRouter);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeDelivery(): CallbackDelivery
    {
        $subscription = CallbackSubscription::create([
            'merchant_id' => Str::uuid()->toString(),
            'callback_url' => 'https://merchant.example/webhooks',
            'event_types' => ['payment.captured'],
        ]);

        return CallbackDelivery::create([
            'subscription_id' => $subscription->id,
            'payment_id' => Str::uuid()->toString(),
            'merchant_id' => $subscription->merchant_id,
            'event_type' => 'payment.captured',
            'payload' => ['event_type' => 'payment.captured'],
            'endpoint_url' => $subscription->callback_url,
            'status' => DeliveryStatus::Pending,
            'correlation_id' => Str::uuid()->toString(),
        ]);
    }

    private function makeCommand(CallbackDelivery $delivery, int $attemptNumber = 1, int $maxAttempts = 5): DeliverCallbackCommand
    {
        return new DeliverCallbackCommand(
            messageId: Str::uuid()->toString(),
            correlationId: (string) $delivery->correlation_id,
            callbackId: $delivery->id,
            merchantId: (string) $delivery->merchant_id,
            paymentId: (string) $delivery->payment_id,
            endpointUrl: (string) $delivery->endpoint_url,
            callbackPayload: (array) $delivery->payload,
            signature: 'test-signature',
            attemptNumber: $attemptNumber,
            maxAttempts: $maxAttempts,
        );
    }

    // -------------------------------------------------------------------------
    // Success path
    // -------------------------------------------------------------------------

    public function test_marks_delivery_as_delivered_on_2xx_response(): void
    {
        $this->sender->willSucceed();
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery));

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_records_attempt_on_success(): void
    {
        $this->sender->willSucceed();
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery, attemptNumber: 1));

        $this->assertDatabaseHas('merchant_callback_attempts', [
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'http_status_code' => 200,
        ]);
    }

    public function test_does_not_route_to_retry_on_success(): void
    {
        $this->sender->willSucceed();
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery));

        $this->assertEmpty($this->retryRouter->retried());
        $this->assertEmpty($this->retryRouter->dlq());
    }

    public function test_adds_message_to_inbox_on_success(): void
    {
        $this->sender->willSucceed();
        $delivery = $this->makeDelivery();
        $command = $this->makeCommand($delivery);

        $this->handler->handle($command);

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $command->messageId]);
    }

    // -------------------------------------------------------------------------
    // Temporary failure — retry routing
    // -------------------------------------------------------------------------

    public function test_routes_to_retry_on_5xx_response(): void
    {
        $this->sender->willFailTemporarily(503);
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery, attemptNumber: 1, maxAttempts: 5));

        $this->assertCount(1, $this->retryRouter->retried());
        $this->assertSame(2, $this->retryRouter->retried()[0]['nextAttemptNumber']);
        $this->assertEmpty($this->retryRouter->dlq());
    }

    public function test_routes_to_retry_on_timeout(): void
    {
        $this->sender->willTimeout();
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery, attemptNumber: 2, maxAttempts: 5));

        $this->assertCount(1, $this->retryRouter->retried());
        $this->assertSame(3, $this->retryRouter->retried()[0]['nextAttemptNumber']);
    }

    public function test_records_failure_attempt_on_temporary_failure(): void
    {
        $this->sender->willFailTemporarily(503);
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery, attemptNumber: 1));

        $this->assertDatabaseHas('merchant_callback_attempts', [
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'http_status_code' => 503,
            'failure_reason' => FailureReason::Non2xx->value,
        ]);
    }

    public function test_delivery_stays_pending_on_temporary_failure(): void
    {
        $this->sender->willFailTemporarily(503);
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery, attemptNumber: 1, maxAttempts: 5));

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
    }

    // -------------------------------------------------------------------------
    // Max attempts exhausted → DLQ
    // -------------------------------------------------------------------------

    public function test_routes_to_dlq_when_max_attempts_reached(): void
    {
        $this->sender->willFailTemporarily(503);
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery, attemptNumber: 5, maxAttempts: 5));

        $this->assertEmpty($this->retryRouter->retried());
        $this->assertCount(1, $this->retryRouter->dlq());
    }

    public function test_marks_delivery_as_dlq_when_max_attempts_reached(): void
    {
        $this->sender->willFailTemporarily(503);
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery, attemptNumber: 5, maxAttempts: 5));

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Dlq, $delivery->status);
    }

    // -------------------------------------------------------------------------
    // Permanent failure → DLQ immediately
    // -------------------------------------------------------------------------

    public function test_routes_to_dlq_immediately_on_permanent_failure(): void
    {
        $this->sender->willFailPermanently(400);
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery, attemptNumber: 1, maxAttempts: 5));

        $this->assertEmpty($this->retryRouter->retried());
        $this->assertCount(1, $this->retryRouter->dlq());
    }

    public function test_marks_delivery_as_dlq_on_permanent_failure(): void
    {
        $this->sender->willFailPermanently(400);
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery, attemptNumber: 1, maxAttempts: 5));

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Dlq, $delivery->status);
    }

    public function test_records_attempt_on_permanent_failure(): void
    {
        $this->sender->willFailPermanently(404);
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery, attemptNumber: 1));

        $this->assertDatabaseHas('merchant_callback_attempts', [
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'http_status_code' => 404,
        ]);
    }

    // -------------------------------------------------------------------------
    // Retry body carries incremented attempt number
    // -------------------------------------------------------------------------

    public function test_retry_message_carries_incremented_attempt_number(): void
    {
        $this->sender->willFailTemporarily(503);
        $delivery = $this->makeDelivery();

        $this->handler->handle($this->makeCommand($delivery, attemptNumber: 2, maxAttempts: 5));

        $retried = $this->retryRouter->retried()[0];
        $retryMessage = json_decode($retried['body'], true);

        $this->assertSame(3, $retryMessage['attempt_number']);
        $this->assertSame(5, $retryMessage['max_attempts']);
    }

    public function test_retry_message_preserves_correlation_id(): void
    {
        $this->sender->willFailTemporarily(503);
        $delivery = $this->makeDelivery();
        $command = $this->makeCommand($delivery);

        $this->handler->handle($command);

        $retried = $this->retryRouter->retried()[0];
        $retryMessage = json_decode($retried['body'], true);

        $this->assertSame($command->correlationId, $retryMessage['correlation_id']);
    }

    // -------------------------------------------------------------------------
    // Idempotency — duplicate messages
    // -------------------------------------------------------------------------

    public function test_skips_processing_when_message_already_in_inbox(): void
    {
        $this->sender->willSucceed();
        $delivery = $this->makeDelivery();
        $command = $this->makeCommand($delivery);

        // First call processes and marks delivered
        $this->handler->handle($command);

        // Reset the delivery status to pending to verify second call is skipped
        CallbackDelivery::where('id', $delivery->id)->update(['status' => DeliveryStatus::Pending]);

        // Second call with same message_id should be a no-op
        $this->handler->handle($command);

        $delivery->refresh();
        // Still pending — the second call did nothing
        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $this->assertCount(1, CallbackAttempt::where('delivery_id', $delivery->id)->get());
    }

    // -------------------------------------------------------------------------
    // Inbox ordering — failure path
    // -------------------------------------------------------------------------

    public function test_inbox_not_written_when_routing_throws_on_temporary_failure(): void
    {
        $this->sender->willFailTemporarily(503);
        $delivery = $this->makeDelivery();
        $command = $this->makeCommand($delivery, attemptNumber: 1, maxAttempts: 5);

        // Swap in a router that always throws on retry
        $throwingRouter = new class implements CallbackRetryRouterInterface
        {
            public function routeToRetry(string $messageId, string $body, int $nextAttemptNumber): void
            {
                throw new \RuntimeException('RabbitMQ unavailable');
            }

            public function routeToDlq(string $messageId, string $body): void {}
        };

        $handler = new DeliverCallbackHandler($this->sender, $throwingRouter);

        try {
            $handler->handle($command);
        } catch (\RuntimeException) {
            // expected
        }

        // Inbox must NOT be written — message must remain reprocessable
        $this->assertDatabaseMissing('inbox_messages', ['message_id' => $command->messageId]);
    }

    public function test_inbox_written_after_successful_routing_on_temporary_failure(): void
    {
        $this->sender->willFailTemporarily(503);
        $delivery = $this->makeDelivery();
        $command = $this->makeCommand($delivery, attemptNumber: 1, maxAttempts: 5);

        $this->handler->handle($command);

        $this->assertDatabaseHas('inbox_messages', ['message_id' => $command->messageId]);
    }

    // -------------------------------------------------------------------------
    // DeliverCallbackCommand::fromArray
    // -------------------------------------------------------------------------

    public function test_from_array_throws_on_missing_required_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: endpoint_url');

        DeliverCallbackCommand::fromArray([
            'message_id' => 'msg-1',
            'correlation_id' => 'corr-1',
            'callback_id' => 'cb-1',
            'merchant_id' => 'merch-1',
            'payment_id' => 'pay-1',
            // endpoint_url intentionally absent
            'callback_payload' => [],
            'signature' => 'sig',
            'attempt_number' => 1,
            'max_attempts' => 5,
        ]);
    }

    public function test_from_array_parses_all_fields(): void
    {
        $data = [
            'message_id' => 'msg-1',
            'correlation_id' => 'corr-1',
            'callback_id' => 'cb-1',
            'merchant_id' => 'merch-1',
            'payment_id' => 'pay-1',
            'endpoint_url' => 'https://example.com/hook',
            'callback_payload' => ['event_type' => 'payment.captured'],
            'signature' => 'sig-abc',
            'attempt_number' => 2,
            'max_attempts' => 5,
        ];

        $command = DeliverCallbackCommand::fromArray($data);

        $this->assertSame('msg-1', $command->messageId);
        $this->assertSame('corr-1', $command->correlationId);
        $this->assertSame('cb-1', $command->callbackId);
        $this->assertSame('merch-1', $command->merchantId);
        $this->assertSame('pay-1', $command->paymentId);
        $this->assertSame('https://example.com/hook', $command->endpointUrl);
        $this->assertSame(['event_type' => 'payment.captured'], $command->callbackPayload);
        $this->assertSame('sig-abc', $command->signature);
        $this->assertSame(2, $command->attemptNumber);
        $this->assertSame(5, $command->maxAttempts);
    }
}
