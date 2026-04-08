<?php

namespace Tests\Feature\Refund;

use App\Domain\Payment\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitiateRefundTest extends TestCase
{
    use RefreshDatabase;

    private string $merchantId = '550e8400-e29b-41d4-a716-446655440000';

    private string $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    private function createCapturedPayment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'merchant_id' => $this->merchantId,
            'external_reference' => 'order-abc-123',
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'captured',
            'correlation_id' => $this->correlationId,
        ], $overrides));
    }

    private function validPayload(string $paymentId, array $overrides = []): array
    {
        return array_merge([
            'payment_id' => $paymentId,
            'merchant_id' => $this->merchantId,
            'amount' => 1000,
            'correlation_id' => $this->correlationId,
        ], $overrides);
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_creates_refund_and_returns_201(): void
    {
        $payment = $this->createCapturedPayment();

        $response = $this->postJson('/api/v1/refunds', $this->validPayload($payment->id));

        $response->assertStatus(201)
            ->assertJsonStructure(['refund_id', 'payment_id', 'status', 'amount', 'currency'])
            ->assertJsonFragment([
                'payment_id' => $payment->id,
                'status' => 'pending',
                'amount' => 1000,
                'currency' => 'USD',
            ]);
    }

    public function test_returns_a_ulid_refund_id(): void
    {
        $payment = $this->createCapturedPayment();

        $response = $this->postJson('/api/v1/refunds', $this->validPayload($payment->id));

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $response->json('refund_id'));
    }

    public function test_creates_outbox_event_for_refund(): void
    {
        $payment = $this->createCapturedPayment();

        $this->postJson('/api/v1/refunds', $this->validPayload($payment->id))->assertStatus(201);

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_type' => 'Refund',
            'event_type' => 'refund.initiated.v1',
            'published_at' => null,
        ]);
    }

    public function test_refund_equal_to_payment_amount_is_allowed(): void
    {
        $payment = $this->createCapturedPayment(['amount' => 5000]);

        $response = $this->postJson('/api/v1/refunds', $this->validPayload($payment->id, ['amount' => 5000]));

        $response->assertStatus(201)->assertJsonFragment(['amount' => 5000]);
    }

    // -----------------------------------------------------------------------
    // Not found / merchant isolation
    // -----------------------------------------------------------------------

    public function test_returns_404_when_payment_not_found(): void
    {
        $response = $this->postJson('/api/v1/refunds', $this->validPayload('00000000000000000000000000'));

        $response->assertStatus(404)->assertJsonFragment(['message' => 'Payment not found.']);
    }

    public function test_returns_404_when_payment_belongs_to_another_merchant(): void
    {
        $payment = $this->createCapturedPayment(['merchant_id' => '550e8400-e29b-41d4-a716-000000000001']);

        $response = $this->postJson('/api/v1/refunds', $this->validPayload($payment->id, [
            'merchant_id' => $this->merchantId,
        ]));

        $response->assertStatus(404)->assertJsonFragment(['message' => 'Payment not found.']);
    }

    // -----------------------------------------------------------------------
    // Status guard
    // -----------------------------------------------------------------------

    public function test_returns_422_when_payment_is_not_captured(): void
    {
        $payment = $this->createCapturedPayment(['status' => 'initiated']);

        $response = $this->postJson('/api/v1/refunds', $this->validPayload($payment->id));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_id']);
    }

    public function test_returns_422_when_payment_is_already_refunded(): void
    {
        $payment = $this->createCapturedPayment(['status' => 'refunded']);

        $response = $this->postJson('/api/v1/refunds', $this->validPayload($payment->id));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_id']);
    }

    // -----------------------------------------------------------------------
    // Amount guard
    // -----------------------------------------------------------------------

    public function test_returns_422_when_refund_amount_exceeds_payment_amount(): void
    {
        $payment = $this->createCapturedPayment(['amount' => 5000]);

        $response = $this->postJson('/api/v1/refunds', $this->validPayload($payment->id, ['amount' => 5001]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    public function test_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/refunds', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_id', 'merchant_id', 'amount', 'correlation_id']);
    }

    public function test_validates_amount_must_be_at_least_one(): void
    {
        $payment = $this->createCapturedPayment();

        $response = $this->postJson('/api/v1/refunds', $this->validPayload($payment->id, ['amount' => 0]));

        $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_validates_merchant_id_must_be_uuid(): void
    {
        $payment = $this->createCapturedPayment();

        $response = $this->postJson('/api/v1/refunds', $this->validPayload($payment->id, ['merchant_id' => 'not-a-uuid']));

        $response->assertStatus(422)->assertJsonValidationErrors(['merchant_id']);
    }

    public function test_validates_correlation_id_must_be_uuid(): void
    {
        $payment = $this->createCapturedPayment();

        $response = $this->postJson('/api/v1/refunds', $this->validPayload($payment->id, ['correlation_id' => 'not-a-uuid']));

        $response->assertStatus(422)->assertJsonValidationErrors(['correlation_id']);
    }

    // -----------------------------------------------------------------------
    // Transaction integrity
    // -----------------------------------------------------------------------

    public function test_refund_and_outbox_are_created_atomically(): void
    {
        $payment = $this->createCapturedPayment();

        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('outbox_events', 0);

        $this->postJson('/api/v1/refunds', $this->validPayload($payment->id))->assertStatus(201);

        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseCount('outbox_events', 1);
    }
}
