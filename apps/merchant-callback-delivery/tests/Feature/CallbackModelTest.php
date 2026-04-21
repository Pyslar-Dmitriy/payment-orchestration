<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Callback\CallbackAttempt;
use App\Domain\Callback\CallbackDelivery;
use App\Domain\Callback\CallbackSubscription;
use App\Domain\Callback\DeliveryStatus;
use App\Domain\Callback\FailureReason;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CallbackModelTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // CallbackSubscription
    // -------------------------------------------------------------------------

    public function test_can_create_callback_subscription(): void
    {
        $sub = CallbackSubscription::create([
            'merchant_id' => Str::uuid()->toString(),
            'callback_url' => 'https://merchant.example/webhooks',
            'signing_secret' => 'secret-abc',
            'signing_algorithm' => 'hmac-sha256',
            'event_types' => ['payment.captured', 'refund.completed'],
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('merchant_callback_subscriptions', [
            'callback_url' => 'https://merchant.example/webhooks',
            'signing_algorithm' => 'hmac-sha256',
            'is_active' => true,
        ]);
        $this->assertNotEmpty($sub->id);
        $this->assertSame(['payment.captured', 'refund.completed'], $sub->event_types);
    }

    public function test_subscription_is_active_by_default(): void
    {
        $sub = CallbackSubscription::create([
            'merchant_id' => Str::uuid()->toString(),
            'callback_url' => 'https://merchant.example/hooks',
            'event_types' => ['payment.captured'],
        ]);

        $this->assertTrue($sub->refresh()->is_active);
    }

    public function test_subscription_enforces_unique_merchant_url(): void
    {
        $merchantId = Str::uuid()->toString();

        CallbackSubscription::create([
            'merchant_id' => $merchantId,
            'callback_url' => 'https://merchant.example/webhooks',
            'event_types' => ['payment.captured'],
        ]);

        $this->expectException(QueryException::class);

        CallbackSubscription::create([
            'merchant_id' => $merchantId,
            'callback_url' => 'https://merchant.example/webhooks',
            'event_types' => ['refund.completed'],
        ]);
    }

    public function test_different_merchants_can_share_same_url(): void
    {
        CallbackSubscription::create([
            'merchant_id' => Str::uuid()->toString(),
            'callback_url' => 'https://merchant.example/webhooks',
            'event_types' => ['payment.captured'],
        ]);

        CallbackSubscription::create([
            'merchant_id' => Str::uuid()->toString(),
            'callback_url' => 'https://merchant.example/webhooks',
            'event_types' => ['payment.captured'],
        ]);

        $this->assertDatabaseCount('merchant_callback_subscriptions', 2);
    }

    public function test_same_merchant_can_register_different_urls(): void
    {
        $merchantId = Str::uuid()->toString();

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

        $this->assertDatabaseCount('merchant_callback_subscriptions', 2);
    }

    // -------------------------------------------------------------------------
    // CallbackDelivery
    // -------------------------------------------------------------------------

    public function test_can_create_delivery_linked_to_subscription(): void
    {
        $sub = $this->makeSubscription();

        $delivery = CallbackDelivery::create([
            'subscription_id' => $sub->id,
            'payment_id' => '01HV5E1BXHF9MEVV1A2K3J4YZQ',
            'merchant_id' => $sub->merchant_id,
            'event_type' => 'payment.captured',
            'payload' => ['payment_id' => '01HV5E1BXHF9MEVV1A2K3J4YZQ', 'amount' => 10000],
            'endpoint_url' => $sub->callback_url,
            'status' => DeliveryStatus::Pending,
            'correlation_id' => Str::uuid()->toString(),
        ]);

        $this->assertDatabaseHas('merchant_callback_deliveries', [
            'subscription_id' => $sub->id,
            'event_type' => 'payment.captured',
            'status' => 'pending',
            'attempt_count' => 0,
        ]);
        $this->assertNotEmpty($delivery->id);
    }

    public function test_delivery_status_casts_to_enum(): void
    {
        $sub = $this->makeSubscription();

        $delivery = CallbackDelivery::create([
            'subscription_id' => $sub->id,
            'merchant_id' => $sub->merchant_id,
            'event_type' => 'payment.captured',
            'payload' => [],
            'endpoint_url' => $sub->callback_url,
            'status' => DeliveryStatus::Delivered,
            'correlation_id' => Str::uuid()->toString(),
        ]);

        $fresh = CallbackDelivery::find($delivery->id);
        $this->assertSame(DeliveryStatus::Delivered, $fresh->status);
    }

    public function test_delivery_survives_subscription_deletion(): void
    {
        $sub = $this->makeSubscription();

        $delivery = CallbackDelivery::create([
            'subscription_id' => $sub->id,
            'merchant_id' => $sub->merchant_id,
            'event_type' => 'payment.captured',
            'payload' => [],
            'endpoint_url' => $sub->callback_url,
            'status' => DeliveryStatus::Delivered,
            'correlation_id' => Str::uuid()->toString(),
        ]);

        $sub->delete();

        $fresh = CallbackDelivery::find($delivery->id);
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->subscription_id); // FK set to null on delete
    }

    // -------------------------------------------------------------------------
    // CallbackAttempt — delivery history tracing
    // -------------------------------------------------------------------------

    public function test_can_record_attempt_for_delivery(): void
    {
        $delivery = $this->makeDelivery();

        CallbackAttempt::create([
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'attempted_at' => now(),
            'http_status_code' => 200,
            'duration_ms' => 142,
            'failure_reason' => null,
        ]);

        $this->assertDatabaseHas('merchant_callback_attempts', [
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'http_status_code' => 200,
            'failure_reason' => null,
        ]);
    }

    public function test_can_trace_delivery_history_through_attempts(): void
    {
        $delivery = $this->makeDelivery();

        CallbackAttempt::create([
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'attempted_at' => now()->subSeconds(30),
            'failure_reason' => FailureReason::Timeout,
        ]);

        CallbackAttempt::create([
            'delivery_id' => $delivery->id,
            'attempt_number' => 2,
            'attempted_at' => now(),
            'http_status_code' => 200,
            'duration_ms' => 210,
        ]);

        $attempts = $delivery->attempts;

        $this->assertCount(2, $attempts);
        $this->assertSame(1, $attempts->first()->attempt_number);
        $this->assertSame(FailureReason::Timeout, $attempts->first()->failure_reason);
        $this->assertSame(2, $attempts->last()->attempt_number);
        $this->assertNull($attempts->last()->failure_reason);
    }

    public function test_attempt_records_why_a_callback_failed(): void
    {
        $delivery = $this->makeDelivery();

        CallbackAttempt::create([
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'attempted_at' => now(),
            'http_status_code' => 503,
            'response_body' => 'Service Unavailable',
            'failure_reason' => FailureReason::Non2xx,
            'duration_ms' => 88,
        ]);

        $attempt = CallbackAttempt::first();

        $this->assertSame(FailureReason::Non2xx, $attempt->failure_reason);
        $this->assertSame(503, $attempt->http_status_code);
        $this->assertSame('Service Unavailable', $attempt->response_body);
    }

    public function test_attempt_attempt_number_unique_per_delivery(): void
    {
        $delivery = $this->makeDelivery();

        CallbackAttempt::create([
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'attempted_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        CallbackAttempt::create([
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'attempted_at' => now(),
        ]);
    }

    public function test_attempt_has_no_updated_at(): void
    {
        $delivery = $this->makeDelivery();

        $attempt = CallbackAttempt::create([
            'delivery_id' => $delivery->id,
            'attempt_number' => 1,
            'attempted_at' => now(),
        ]);

        $this->assertNull($attempt->updated_at);
        $this->assertArrayNotHasKey('updated_at', $attempt->toArray());
    }

    public function test_subscription_deliveries_relationship(): void
    {
        $sub = $this->makeSubscription();

        CallbackDelivery::create([
            'subscription_id' => $sub->id,
            'merchant_id' => $sub->merchant_id,
            'event_type' => 'payment.captured',
            'payload' => [],
            'endpoint_url' => $sub->callback_url,
            'status' => DeliveryStatus::Pending,
            'correlation_id' => Str::uuid()->toString(),
        ]);

        CallbackDelivery::create([
            'subscription_id' => $sub->id,
            'merchant_id' => $sub->merchant_id,
            'event_type' => 'refund.completed',
            'payload' => [],
            'endpoint_url' => $sub->callback_url,
            'status' => DeliveryStatus::Delivered,
            'correlation_id' => Str::uuid()->toString(),
        ]);

        $this->assertCount(2, $sub->deliveries);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeSubscription(string $merchantId = 'a0000000-0000-4000-8000-000000000001'): CallbackSubscription
    {
        return CallbackSubscription::create([
            'merchant_id' => $merchantId,
            'callback_url' => 'https://merchant.example/webhooks',
            'signing_secret' => 'test-secret',
            'signing_algorithm' => 'hmac-sha256',
            'event_types' => ['payment.captured', 'refund.completed'],
        ]);
    }

    private function makeDelivery(?CallbackSubscription $sub = null): CallbackDelivery
    {
        $sub ??= $this->makeSubscription();

        return CallbackDelivery::create([
            'subscription_id' => $sub->id,
            'payment_id' => '01HV5E1BXHF9MEVV1A2K3J4YZQ',
            'merchant_id' => $sub->merchant_id,
            'event_type' => 'payment.captured',
            'payload' => ['payment_id' => '01HV5E1BXHF9MEVV1A2K3J4YZQ'],
            'endpoint_url' => $sub->callback_url,
            'status' => DeliveryStatus::Pending,
            'correlation_id' => Str::uuid()->toString(),
        ]);
    }
}
