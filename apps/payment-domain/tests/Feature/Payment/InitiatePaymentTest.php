<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\PaymentAttemptStatus;
use App\Domain\Payment\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitiatePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'merchant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'amount' => 1000,
            'currency' => 'USD',
            'external_reference' => 'order-abc-123',
            'idempotency_key' => 'idem-key-test-001',
            'provider_id' => 'mock',
            'customer_reference' => 'cust-456',
            'payment_method_reference' => 'pm_test_token',
            'metadata' => ['source' => 'web'],
            'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        ], $overrides);
    }

    public function test_creates_payment_with_created_status(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonStructure(['payment_id', 'attempt_id', 'status'])
            ->assertJsonFragment(['status' => PaymentStatus::CREATED->value]);
    }

    public function test_returns_a_ulid_payment_id(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload());

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $response->json('payment_id'));
    }

    public function test_returns_a_ulid_attempt_id(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload());

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $response->json('attempt_id'));
    }

    public function test_creates_payment_status_history_entry(): void
    {
        $this->postJson('/api/v1/payments', $this->validPayload())->assertStatus(201);

        $this->assertDatabaseCount('payment_status_history', 1);
        $this->assertDatabaseHas('payment_status_history', [
            'from_status' => null,
            'to_status' => PaymentStatus::CREATED->value,
        ]);
    }

    public function test_creates_payment_attempt_with_pending_status(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload())->assertStatus(201);

        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseHas('payment_attempts', [
            'id' => $response->json('attempt_id'),
            'attempt_number' => 1,
            'provider_id' => 'mock',
            'status' => PaymentAttemptStatus::PENDING->value,
        ]);
    }

    public function test_payment_attempt_is_linked_to_payment(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload())->assertStatus(201);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $response->json('payment_id'),
            'attempt_number' => 1,
        ]);
    }

    public function test_creates_outbox_event_pending_publish(): void
    {
        $this->postJson('/api/v1/payments', $this->validPayload())->assertStatus(201);

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_type' => 'Payment',
            'event_type' => 'payment.initiated.v1',
            'published_at' => null,
        ]);
    }

    public function test_outbox_event_payload_includes_attempt_id_and_provider(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload())->assertStatus(201);

        $event = \DB::table('outbox_events')->first();
        $payload = json_decode($event->payload, true);

        $this->assertEquals($response->json('attempt_id'), $payload['attempt_id']);
        $this->assertEquals('mock', $payload['provider_id']);
    }

    public function test_correlation_id_is_stored_on_payment_and_history(): void
    {
        $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

        $this->postJson('/api/v1/payments', $this->validPayload(['correlation_id' => $correlationId]))
            ->assertStatus(201);

        $this->assertDatabaseHas('payments', ['correlation_id' => $correlationId]);
        $this->assertDatabaseHas('payment_status_history', ['correlation_id' => $correlationId]);
        $this->assertDatabaseHas('payment_attempts', ['correlation_id' => $correlationId]);
    }

    public function test_idempotency_key_is_stored_on_payment(): void
    {
        $this->postJson('/api/v1/payments', $this->validPayload(['idempotency_key' => 'my-unique-key-abc']))->assertStatus(201);

        $this->assertDatabaseHas('payments', ['idempotency_key' => 'my-unique-key-abc']);
    }

    public function test_provider_id_is_stored_on_payment_and_attempt(): void
    {
        $this->postJson('/api/v1/payments', $this->validPayload(['provider_id' => 'stripe']))->assertStatus(201);

        $this->assertDatabaseHas('payments', ['provider_id' => 'stripe']);
        $this->assertDatabaseHas('payment_attempts', ['provider_id' => 'stripe']);
    }

    public function test_duplicate_idempotency_key_returns_200_with_existing_payment(): void
    {
        $payload = $this->validPayload();

        $first = $this->postJson('/api/v1/payments', $payload);
        $first->assertStatus(201);

        $second = $this->postJson('/api/v1/payments', $payload);
        $second->assertStatus(200)
            ->assertJsonFragment(['payment_id' => $first->json('payment_id')])
            ->assertJsonFragment(['status' => PaymentStatus::CREATED->value]);
    }

    public function test_duplicate_idempotency_key_does_not_create_additional_records(): void
    {
        $payload = $this->validPayload();

        $this->postJson('/api/v1/payments', $payload)->assertStatus(201);
        $this->postJson('/api/v1/payments', $payload)->assertStatus(200);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_status_history', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/payments', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['merchant_id', 'amount', 'currency', 'external_reference', 'idempotency_key', 'provider_id', 'correlation_id']);
    }

    public function test_validates_amount_must_be_at_least_one(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload(['amount' => 0]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_validates_currency_must_be_three_characters(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload(['currency' => 'US']));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_validates_merchant_id_must_be_uuid(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload(['merchant_id' => 'not-a-uuid']));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['merchant_id']);
    }

    public function test_validates_correlation_id_must_be_uuid(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload(['correlation_id' => 'not-a-uuid']));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['correlation_id']);
    }

    public function test_optional_fields_can_be_omitted(): void
    {
        $payload = [
            'merchant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'amount' => 500,
            'currency' => 'EUR',
            'external_reference' => 'order-minimal',
            'idempotency_key' => 'idem-key-minimal-001',
            'provider_id' => 'mock',
            'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        ];

        $response = $this->postJson('/api/v1/payments', $payload);

        $response->assertStatus(201);
    }

    public function test_all_writes_are_rolled_back_on_failure(): void
    {
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_status_history', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('outbox_events', 0);

        $this->postJson('/api/v1/payments', $this->validPayload())->assertStatus(201);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_status_history', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('outbox_events', 1);
    }
}
