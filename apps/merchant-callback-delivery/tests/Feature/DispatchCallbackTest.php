<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Callback\CallbackDelivery;
use App\Domain\Callback\CallbackSubscription;
use App\Domain\Callback\DeliveryStatus;
use App\Infrastructure\RabbitMq\CallbackQueuePublisherInterface;
use App\Infrastructure\RabbitMq\FakeCallbackPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DispatchCallbackTest extends TestCase
{
    use RefreshDatabase;

    private FakeCallbackPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publisher = new FakeCallbackPublisher;
        $this->app->instance(CallbackQueuePublisherInterface::class, $this->publisher);
        config(['services.internal.secret' => 'test-secret']);
    }

    private function validPayload(array $override = []): array
    {
        return array_merge([
            'payment_id' => Str::uuid()->toString(),
            'merchant_id' => 'a0000000-0000-4000-8000-000000000001',
            'event_type' => 'payment.captured',
            'amount_value' => 9999,
            'amount_currency' => 'USD',
            'status' => 'captured',
            'occurred_at' => '2026-04-21T12:00:00Z',
            'correlation_id' => Str::uuid()->toString(),
        ], $override);
    }

    private function makeSubscription(string $merchantId, array $eventTypes = ['payment.captured']): CallbackSubscription
    {
        return CallbackSubscription::create([
            'merchant_id' => $merchantId,
            'callback_url' => 'https://merchant.example/webhooks',
            'signing_secret' => 'hmac-test-secret',
            'signing_algorithm' => 'hmac-sha256',
            'event_types' => $eventTypes,
        ]);
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    public function test_rejects_request_without_internal_secret(): void
    {
        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload())
            ->assertStatus(401);
    }

    public function test_rejects_request_with_wrong_internal_secret(): void
    {
        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload(), [
            'X-Internal-Secret' => 'wrong-secret',
        ])->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_validates_required_fields(): void
    {
        $this->postJson('/api/v1/callbacks/dispatch', [], [
            'X-Internal-Secret' => 'test-secret',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['payment_id', 'merchant_id', 'event_type', 'amount_value', 'amount_currency', 'status', 'occurred_at', 'correlation_id']);
    }

    public function test_rejects_invalid_event_type(): void
    {
        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload(['event_type' => 'unknown.event']), [
            'X-Internal-Secret' => 'test-secret',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['event_type']);
    }

    public function test_rejects_non_uppercase_currency(): void
    {
        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload(['amount_currency' => 'usd']), [
            'X-Internal-Secret' => 'test-secret',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['amount_currency']);
    }

    public function test_rejects_negative_amount(): void
    {
        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload(['amount_value' => -1]), [
            'X-Internal-Secret' => 'test-secret',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['amount_value']);
    }

    // -------------------------------------------------------------------------
    // Happy path — subscriptions found
    // -------------------------------------------------------------------------

    public function test_dispatches_to_matching_subscription_and_returns_202(): void
    {
        $merchantId = 'a0000000-0000-4000-8000-000000000001';
        $this->makeSubscription($merchantId);
        $payload = $this->validPayload(['merchant_id' => $merchantId]);

        $response = $this->postJson('/api/v1/callbacks/dispatch', $payload, [
            'X-Internal-Secret' => 'test-secret',
        ]);

        $response->assertStatus(202)
            ->assertJson(['dispatched_count' => 1]);

        $this->assertCount(1, $this->publisher->published());
    }

    public function test_creates_callback_delivery_record_in_pending_state(): void
    {
        $merchantId = 'a0000000-0000-4000-8000-000000000002';
        $this->makeSubscription($merchantId);
        $paymentId = Str::uuid()->toString();
        $payload = $this->validPayload(['merchant_id' => $merchantId, 'payment_id' => $paymentId]);

        $this->postJson('/api/v1/callbacks/dispatch', $payload, [
            'X-Internal-Secret' => 'test-secret',
        ]);

        $this->assertDatabaseHas('merchant_callback_deliveries', [
            'payment_id' => $paymentId,
            'merchant_id' => $merchantId,
            'event_type' => 'payment.captured',
            'status' => 'pending',
        ]);
    }

    public function test_enqueued_message_contains_correct_fields(): void
    {
        $merchantId = 'a0000000-0000-4000-8000-000000000003';
        $paymentId = Str::uuid()->toString();
        $correlationId = Str::uuid()->toString();
        $this->makeSubscription($merchantId);
        $payload = $this->validPayload([
            'merchant_id' => $merchantId,
            'payment_id' => $paymentId,
            'correlation_id' => $correlationId,
        ]);

        $this->postJson('/api/v1/callbacks/dispatch', $payload, [
            'X-Internal-Secret' => 'test-secret',
        ]);

        $published = $this->publisher->published()[0];
        $message = json_decode($published['body'], true);

        $this->assertSame('merchant.callback.dispatch', $published['queue']);
        $this->assertSame('merchant_callback_dispatch', $message['message_type']);
        $this->assertSame($merchantId, $message['merchant_id']);
        $this->assertSame($paymentId, $message['payment_id']);
        $this->assertSame($correlationId, $message['correlation_id']);
        $this->assertSame('merchant-callback-delivery', $message['source_service']);
        $this->assertSame(1, $message['attempt_number']);
        $this->assertArrayHasKey('callback_id', $message);
        $this->assertArrayHasKey('signature', $message);
        $this->assertArrayHasKey('callback_payload', $message);
    }

    public function test_callback_payload_structure(): void
    {
        $merchantId = 'a0000000-0000-4000-8000-000000000004';
        $this->makeSubscription($merchantId);
        $payload = $this->validPayload([
            'merchant_id' => $merchantId,
            'event_type' => 'payment.captured',
            'amount_value' => 5000,
            'amount_currency' => 'EUR',
            'status' => 'captured',
        ]);

        $this->postJson('/api/v1/callbacks/dispatch', $payload, [
            'X-Internal-Secret' => 'test-secret',
        ]);

        $message = json_decode($this->publisher->published()[0]['body'], true);
        $callbackPayload = $message['callback_payload'];

        $this->assertSame('payment.captured', $callbackPayload['event_type']);
        $this->assertSame(['value' => 5000, 'currency' => 'EUR'], $callbackPayload['amount']);
        $this->assertSame('captured', $callbackPayload['status']);
    }

    public function test_signature_is_hmac_sha256_of_callback_payload(): void
    {
        $merchantId = 'a0000000-0000-4000-8000-000000000005';
        $secret = 'my-signing-secret';
        CallbackSubscription::create([
            'merchant_id' => $merchantId,
            'callback_url' => 'https://merchant.example/hooks',
            'signing_secret' => $secret,
            'signing_algorithm' => 'hmac-sha256',
            'event_types' => ['payment.captured'],
        ]);

        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload(['merchant_id' => $merchantId]), [
            'X-Internal-Secret' => 'test-secret',
        ]);

        $message = json_decode($this->publisher->published()[0]['body'], true);
        $expectedSignature = hash_hmac('sha256', json_encode($message['callback_payload']), $secret);

        $this->assertSame($expectedSignature, $message['signature']);
    }

    public function test_dispatches_to_multiple_subscriptions_for_same_merchant(): void
    {
        $merchantId = 'a0000000-0000-4000-8000-000000000006';
        CallbackSubscription::create([
            'merchant_id' => $merchantId,
            'callback_url' => 'https://merchant.example/hooks/a',
            'event_types' => ['payment.captured'],
        ]);
        CallbackSubscription::create([
            'merchant_id' => $merchantId,
            'callback_url' => 'https://merchant.example/hooks/b',
            'event_types' => ['payment.captured'],
        ]);

        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload(['merchant_id' => $merchantId]), [
            'X-Internal-Secret' => 'test-secret',
        ]);

        $this->assertCount(2, $this->publisher->published());
        $this->assertDatabaseCount('merchant_callback_deliveries', 2);
    }

    // -------------------------------------------------------------------------
    // No subscriptions
    // -------------------------------------------------------------------------

    public function test_returns_202_with_zero_when_no_subscriptions_exist(): void
    {
        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload(), [
            'X-Internal-Secret' => 'test-secret',
        ])->assertStatus(202)
            ->assertJson(['dispatched_count' => 0]);

        $this->assertEmpty($this->publisher->published());
    }

    public function test_skips_inactive_subscriptions(): void
    {
        $merchantId = 'a0000000-0000-4000-8000-000000000007';
        CallbackSubscription::create([
            'merchant_id' => $merchantId,
            'callback_url' => 'https://merchant.example/hooks',
            'event_types' => ['payment.captured'],
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload(['merchant_id' => $merchantId]), [
            'X-Internal-Secret' => 'test-secret',
        ])->assertJson(['dispatched_count' => 0]);

        $this->assertEmpty($this->publisher->published());
    }

    public function test_skips_subscriptions_for_different_event_type(): void
    {
        $merchantId = 'a0000000-0000-4000-8000-000000000008';
        CallbackSubscription::create([
            'merchant_id' => $merchantId,
            'callback_url' => 'https://merchant.example/hooks',
            'event_types' => ['refund.completed'],
        ]);

        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload([
            'merchant_id' => $merchantId,
            'event_type' => 'payment.captured',
        ]), ['X-Internal-Secret' => 'test-secret'])
            ->assertJson(['dispatched_count' => 0]);
    }

    public function test_skips_subscriptions_for_different_merchant(): void
    {
        $this->makeSubscription('a0000000-0000-4000-8000-000000000009');

        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload([
            'merchant_id' => 'b0000000-0000-4000-8000-000000000001',
        ]), ['X-Internal-Secret' => 'test-secret'])
            ->assertJson(['dispatched_count' => 0]);
    }

    // -------------------------------------------------------------------------
    // Refund events
    // -------------------------------------------------------------------------

    public function test_includes_refund_id_in_callback_payload_for_refund_events(): void
    {
        $merchantId = 'a0000000-0000-4000-8000-000000000010';
        CallbackSubscription::create([
            'merchant_id' => $merchantId,
            'callback_url' => 'https://merchant.example/hooks',
            'event_types' => ['refund.completed'],
        ]);
        $refundId = Str::uuid()->toString();

        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload([
            'merchant_id' => $merchantId,
            'event_type' => 'refund.completed',
            'status' => 'refunded',
            'refund_id' => $refundId,
        ]), ['X-Internal-Secret' => 'test-secret']);

        $message = json_decode($this->publisher->published()[0]['body'], true);
        $this->assertSame($refundId, $message['callback_payload']['refund_id']);
    }

    public function test_refund_event_preserves_payment_id_in_callback_payload(): void
    {
        $merchantId = 'a0000000-0000-4000-8000-000000000012';
        CallbackSubscription::create([
            'merchant_id' => $merchantId,
            'callback_url' => 'https://merchant.example/hooks',
            'event_types' => ['refund.completed'],
        ]);
        $paymentId = Str::uuid()->toString();
        $refundId = Str::uuid()->toString();

        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload([
            'merchant_id' => $merchantId,
            'payment_id' => $paymentId,
            'event_type' => 'refund.completed',
            'status' => 'refunded',
            'refund_id' => $refundId,
        ]), ['X-Internal-Secret' => 'test-secret']);

        $message = json_decode($this->publisher->published()[0]['body'], true);
        $this->assertSame($paymentId, $message['callback_payload']['payment_id']);
        $this->assertSame($refundId, $message['callback_payload']['refund_id']);
        $this->assertNotSame($refundId, $message['callback_payload']['payment_id']);
    }

    // -------------------------------------------------------------------------
    // Delivery ID traceability
    // -------------------------------------------------------------------------

    public function test_callback_id_in_message_matches_delivery_record(): void
    {
        $merchantId = 'a0000000-0000-4000-8000-000000000011';
        $this->makeSubscription($merchantId);

        $this->postJson('/api/v1/callbacks/dispatch', $this->validPayload(['merchant_id' => $merchantId]), [
            'X-Internal-Secret' => 'test-secret',
        ]);

        $message = json_decode($this->publisher->published()[0]['body'], true);
        $delivery = CallbackDelivery::find($message['callback_id']);

        $this->assertNotNull($delivery);
        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
    }
}
