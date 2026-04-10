<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TransitionPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private string $merchantId = '550e8400-e29b-41d4-a716-446655440000';

    private string $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    private function createPayment(PaymentStatus $status, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'merchant_id' => $this->merchantId,
            'external_reference' => 'order-test-001',
            'amount' => 5000,
            'currency' => 'USD',
            'status' => $status,
            'version' => 0,
            'correlation_id' => $this->correlationId,
        ], $overrides));
    }

    private function validPayload(string $status, array $overrides = []): array
    {
        return array_merge([
            'merchant_id' => $this->merchantId,
            'status' => $status,
            'correlation_id' => $this->correlationId,
        ], $overrides);
    }

    private function patchStatus(string $paymentId, array $payload): TestResponse
    {
        return $this->patchJson("/api/v1/payments/{$paymentId}/status", $payload); // @phpstan-ignore-line
    }

    // -----------------------------------------------------------------------
    // mark pending_provider
    // -----------------------------------------------------------------------

    public function test_marks_payment_as_pending_provider(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);

        $response = $this->patchStatus($payment->id, $this->validPayload('pending_provider'));

        $response->assertStatus(200)
            ->assertJsonFragment(['payment_id' => $payment->id, 'status' => 'pending_provider']);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending_provider']);
    }

    public function test_pending_provider_writes_status_history(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);

        $this->patchStatus($payment->id, $this->validPayload('pending_provider'))->assertStatus(200);

        $this->assertDatabaseHas('payment_status_history', [
            'payment_id' => $payment->id,
            'from_status' => 'created',
            'to_status' => 'pending_provider',
        ]);
    }

    public function test_pending_provider_writes_outbox_event(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);

        $this->patchStatus($payment->id, $this->validPayload('pending_provider'))->assertStatus(200);

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_type' => 'Payment',
            'aggregate_id' => $payment->id,
            'event_type' => 'payment.pending_provider.v1',
            'published_at' => null,
        ]);
    }

    // -----------------------------------------------------------------------
    // mark authorized
    // -----------------------------------------------------------------------

    public function test_marks_payment_as_authorized(): void
    {
        $payment = $this->createPayment(PaymentStatus::PENDING_PROVIDER);

        $response = $this->patchStatus($payment->id, $this->validPayload('authorized'));

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'authorized']);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'authorized']);
    }

    public function test_authorized_writes_outbox_event(): void
    {
        $payment = $this->createPayment(PaymentStatus::PENDING_PROVIDER);

        $this->patchStatus($payment->id, $this->validPayload('authorized'))->assertStatus(200);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'payment.authorized.v1',
            'aggregate_id' => $payment->id,
            'published_at' => null,
        ]);
    }

    // -----------------------------------------------------------------------
    // mark captured
    // -----------------------------------------------------------------------

    public function test_marks_payment_as_captured(): void
    {
        $payment = $this->createPayment(PaymentStatus::AUTHORIZED);

        $response = $this->patchStatus($payment->id, $this->validPayload('captured'));

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'captured']);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'captured']);
    }

    public function test_captured_writes_outbox_event(): void
    {
        $payment = $this->createPayment(PaymentStatus::AUTHORIZED);

        $this->patchStatus($payment->id, $this->validPayload('captured'))->assertStatus(200);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'payment.captured.v1',
            'aggregate_id' => $payment->id,
            'published_at' => null,
        ]);
    }

    // -----------------------------------------------------------------------
    // mark failed
    // -----------------------------------------------------------------------

    public function test_marks_payment_as_failed_from_pending_provider(): void
    {
        $payment = $this->createPayment(PaymentStatus::PENDING_PROVIDER);

        $response = $this->patchStatus($payment->id, $this->validPayload('failed', [
            'failure_code' => 'insufficient_funds',
            'failure_reason' => 'Card declined due to insufficient funds.',
        ]));

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'failed']);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'failed',
            'failure_code' => 'insufficient_funds',
            'failure_reason' => 'Card declined due to insufficient funds.',
        ]);
    }

    public function test_failed_writes_outbox_event(): void
    {
        $payment = $this->createPayment(PaymentStatus::PENDING_PROVIDER);

        $this->patchStatus($payment->id, $this->validPayload('failed'))->assertStatus(200);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'payment.failed.v1',
            'aggregate_id' => $payment->id,
            'published_at' => null,
        ]);
    }

    // -----------------------------------------------------------------------
    // mark refunding
    // -----------------------------------------------------------------------

    public function test_marks_payment_as_refunding(): void
    {
        $payment = $this->createPayment(PaymentStatus::CAPTURED);

        $response = $this->patchStatus($payment->id, $this->validPayload('refunding'));

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'refunding']);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'refunding']);
    }

    public function test_refunding_writes_outbox_event(): void
    {
        $payment = $this->createPayment(PaymentStatus::CAPTURED);

        $this->patchStatus($payment->id, $this->validPayload('refunding'))->assertStatus(200);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'payment.refunding.v1',
            'aggregate_id' => $payment->id,
            'published_at' => null,
        ]);
    }

    // -----------------------------------------------------------------------
    // mark refunded
    // -----------------------------------------------------------------------

    public function test_marks_payment_as_refunded(): void
    {
        $payment = $this->createPayment(PaymentStatus::REFUNDING);

        $response = $this->patchStatus($payment->id, $this->validPayload('refunded'));

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'refunded']);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'refunded']);
    }

    public function test_refunded_writes_outbox_event(): void
    {
        $payment = $this->createPayment(PaymentStatus::REFUNDING);

        $this->patchStatus($payment->id, $this->validPayload('refunded'))->assertStatus(200);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'payment.refunded.v1',
            'aggregate_id' => $payment->id,
            'published_at' => null,
        ]);
    }

    // -----------------------------------------------------------------------
    // State machine enforcement — invalid transitions
    // -----------------------------------------------------------------------

    public function test_returns_422_when_transition_is_not_allowed(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);

        // Cannot go directly from created → captured (skips pending_provider)
        $response = $this->patchStatus($payment->id, $this->validPayload('captured'));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_returns_422_when_transitioning_terminal_status(): void
    {
        $payment = $this->createPayment(PaymentStatus::FAILED);

        $response = $this->patchStatus($payment->id, $this->validPayload('pending_provider'));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_cannot_jump_from_created_to_refunded(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);

        $response = $this->patchStatus($payment->id, $this->validPayload('refunded'));

        $response->assertStatus(422);
    }

    public function test_failed_status_remains_terminal(): void
    {
        $payment = $this->createPayment(PaymentStatus::FAILED);

        $this->patchStatus($payment->id, $this->validPayload('authorized'))->assertStatus(422);
        $this->patchStatus($payment->id, $this->validPayload('captured'))->assertStatus(422);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'failed']);
    }

    // -----------------------------------------------------------------------
    // Not found / merchant isolation
    // -----------------------------------------------------------------------

    public function test_returns_404_when_payment_not_found(): void
    {
        $response = $this->patchStatus('00000000000000000000000000', $this->validPayload('pending_provider'));

        $response->assertStatus(404)
            ->assertJsonFragment(['message' => 'Payment not found.']);
    }

    public function test_returns_404_when_payment_belongs_to_another_merchant(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED, [
            'merchant_id' => '550e8400-e29b-41d4-a716-000000000001',
        ]);

        $response = $this->patchStatus($payment->id, $this->validPayload('pending_provider', [
            'merchant_id' => $this->merchantId,
        ]));

        $response->assertStatus(404)
            ->assertJsonFragment(['message' => 'Payment not found.']);
    }

    // -----------------------------------------------------------------------
    // Optimistic locking & atomicity
    // -----------------------------------------------------------------------

    public function test_transition_increments_version(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);
        $initialVersion = $payment->version;

        $this->patchStatus($payment->id, $this->validPayload('pending_provider'))->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'version' => $initialVersion + 1,
        ]);
    }

    public function test_status_and_outbox_are_written_atomically(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);

        $this->patchStatus($payment->id, $this->validPayload('pending_provider'))->assertStatus(200);

        $this->assertDatabaseHas('payments', ['status' => 'pending_provider']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'payment.pending_provider.v1']);
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    public function test_validates_required_fields(): void
    {
        $response = $this->patchJson('/api/v1/payments/00000000000000000000000000/status', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['merchant_id', 'status', 'correlation_id']);
    }

    public function test_validates_status_must_be_a_transitionable_value(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);

        $response = $this->patchStatus($payment->id, $this->validPayload('created'));

        // 'created' is not in the allowed list for the status field
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_validates_merchant_id_must_be_uuid(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);

        $response = $this->patchStatus($payment->id, $this->validPayload('pending_provider', [
            'merchant_id' => 'not-a-uuid',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['merchant_id']);
    }

    public function test_validates_correlation_id_must_be_uuid(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);

        $response = $this->patchStatus($payment->id, $this->validPayload('pending_provider', [
            'correlation_id' => 'not-a-uuid',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['correlation_id']);
    }
}
