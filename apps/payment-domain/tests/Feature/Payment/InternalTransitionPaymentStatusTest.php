<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InternalTransitionPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-internal-secret';

    private string $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.internal.secret' => $this->secret]);
    }

    private function createPayment(PaymentStatus $status): Payment
    {
        return Payment::create([
            'merchant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'external_reference' => 'order-001',
            'amount' => 5000,
            'currency' => 'USD',
            'status' => $status,
            'version' => 0,
            'correlation_id' => $this->correlationId,
        ]);
    }

    private function patchStatus(string $paymentId, array $payload, ?string $secret = null): TestResponse
    {
        return $this->patchJson( // @phpstan-ignore-line
            "/api/internal/v1/payments/{$paymentId}/status",
            $payload,
            ['X-Internal-Secret' => $secret ?? $this->secret],
        );
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public function test_returns_401_when_secret_is_missing(): void
    {
        $this->patchJson('/api/internal/v1/payments/00000000000000000000000000/status', [ // @phpstan-ignore-line
            'status' => 'pending_provider',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(401);
    }

    public function test_returns_401_when_secret_is_wrong(): void
    {
        $this->patchStatus('00000000000000000000000000', [
            'status' => 'pending_provider',
            'correlation_id' => $this->correlationId,
        ], 'wrong-secret')->assertStatus(401);
    }

    // ── Status transitions ────────────────────────────────────────────────────

    public function test_marks_payment_pending_provider(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);

        $this->patchStatus($payment->id, [
            'status' => 'pending_provider',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(200)->assertJsonFragment(['status' => 'pending_provider']);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending_provider']);
    }

    public function test_marks_payment_authorized(): void
    {
        $payment = $this->createPayment(PaymentStatus::PENDING_PROVIDER);

        $this->patchStatus($payment->id, [
            'status' => 'authorized',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(200)->assertJsonFragment(['status' => 'authorized']);
    }

    public function test_marks_payment_captured(): void
    {
        $payment = $this->createPayment(PaymentStatus::AUTHORIZED);

        $this->patchStatus($payment->id, [
            'status' => 'captured',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(200)->assertJsonFragment(['status' => 'captured']);
    }

    public function test_marks_payment_failed_with_reason(): void
    {
        $payment = $this->createPayment(PaymentStatus::PENDING_PROVIDER);

        $this->patchStatus($payment->id, [
            'status' => 'failed',
            'correlation_id' => $this->correlationId,
            'failure_code' => 'insufficient_funds',
            'failure_reason' => 'Card declined.',
        ])->assertStatus(200)->assertJsonFragment(['status' => 'failed']);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'failure_code' => 'insufficient_funds',
            'failure_reason' => 'Card declined.',
        ]);
    }

    public function test_marks_payment_requires_reconciliation_from_captured(): void
    {
        $payment = $this->createPayment(PaymentStatus::CAPTURED);

        $this->patchStatus($payment->id, [
            'status' => 'requires_reconciliation',
            'correlation_id' => $this->correlationId,
            'failed_step' => 'ledger_post',
        ])->assertStatus(200)->assertJsonFragment(['status' => 'requires_reconciliation']);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'requires_reconciliation']);
    }

    public function test_marks_payment_requires_reconciliation_from_authorized(): void
    {
        $payment = $this->createPayment(PaymentStatus::AUTHORIZED);

        $this->patchStatus($payment->id, [
            'status' => 'requires_reconciliation',
            'correlation_id' => $this->correlationId,
            'failed_step' => 'provider_status_query',
        ])->assertStatus(200)->assertJsonFragment(['status' => 'requires_reconciliation']);
    }

    // ── Outbox events ─────────────────────────────────────────────────────────

    public function test_pending_provider_writes_outbox_event(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);

        $this->patchStatus($payment->id, [
            'status' => 'pending_provider',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(200);

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $payment->id,
            'event_type' => 'payment.pending_provider.v1',
        ]);
    }

    public function test_requires_reconciliation_writes_outbox_event(): void
    {
        $payment = $this->createPayment(PaymentStatus::CAPTURED);

        $this->patchStatus($payment->id, [
            'status' => 'requires_reconciliation',
            'correlation_id' => $this->correlationId,
            'failed_step' => 'ledger_post',
        ])->assertStatus(200);

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $payment->id,
            'event_type' => 'payment.requires_reconciliation.v1',
        ]);
    }

    // ── Error cases ───────────────────────────────────────────────────────────

    public function test_returns_404_when_payment_not_found(): void
    {
        $this->patchStatus('00000000000000000000000000', [
            'status' => 'pending_provider',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(404);
    }

    public function test_returns_422_when_transition_is_invalid(): void
    {
        $payment = $this->createPayment(PaymentStatus::FAILED);

        $this->patchStatus($payment->id, [
            'status' => 'captured',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(422);
    }

    public function test_returns_422_when_status_is_not_allowed_for_internal(): void
    {
        $payment = $this->createPayment(PaymentStatus::CREATED);

        // 'refunding' is not in the internal status list
        $this->patchStatus($payment->id, [
            'status' => 'refunding',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_validates_required_fields(): void
    {
        $this->patchJson( // @phpstan-ignore-line
            '/api/internal/v1/payments/00000000000000000000000000/status',
            [],
            ['X-Internal-Secret' => $this->secret],
        )->assertStatus(422)->assertJsonValidationErrors(['status', 'correlation_id']);
    }
}
