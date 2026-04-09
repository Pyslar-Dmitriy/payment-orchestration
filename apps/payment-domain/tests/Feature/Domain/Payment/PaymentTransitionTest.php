<?php

namespace Tests\Feature\Domain\Payment;

use App\Domain\Payment\Exceptions\InvalidPaymentTransitionException;
use App\Domain\Payment\Exceptions\PaymentConcurrencyException;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentTransitionTest extends TestCase
{
    use RefreshDatabase;

    private string $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    private function createPayment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'merchant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'external_reference' => 'order-test-123',
            'idempotency_key' => 'idem-transition-test-'.uniqid(),
            'amount' => 1000,
            'currency' => 'USD',
            'status' => PaymentStatus::CREATED,
            'version' => 0,
            'correlation_id' => $this->correlationId,
        ], $overrides));
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_valid_transition_updates_status(): void
    {
        $payment = $this->createPayment();

        $payment->transition(PaymentStatus::PENDING_PROVIDER, $this->correlationId);

        $this->assertSame(PaymentStatus::PENDING_PROVIDER, $payment->status);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::PENDING_PROVIDER->value,
        ]);
    }

    public function test_transition_increments_version(): void
    {
        $payment = $this->createPayment(['version' => 0]);

        $payment->transition(PaymentStatus::PENDING_PROVIDER, $this->correlationId);

        $this->assertSame(1, $payment->version);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'version' => 1]);
    }

    public function test_repeated_transitions_keep_incrementing_version(): void
    {
        $payment = $this->createPayment(['status' => PaymentStatus::PENDING_PROVIDER, 'version' => 0]);

        $payment->transition(PaymentStatus::AUTHORIZED, $this->correlationId);
        $payment->transition(PaymentStatus::CAPTURED, $this->correlationId);

        $this->assertSame(2, $payment->version);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'version' => 2]);
    }

    public function test_transition_creates_status_history_record(): void
    {
        $payment = $this->createPayment();

        $payment->transition(PaymentStatus::PENDING_PROVIDER, $this->correlationId, 'workflow started');

        $this->assertDatabaseHas('payment_status_history', [
            'payment_id' => $payment->id,
            'from_status' => PaymentStatus::CREATED->value,
            'to_status' => PaymentStatus::PENDING_PROVIDER->value,
            'reason' => 'workflow started',
            'correlation_id' => $this->correlationId,
        ]);
    }

    public function test_failure_fields_are_stored_on_failed_transition(): void
    {
        $payment = $this->createPayment(['status' => PaymentStatus::PENDING_PROVIDER]);

        $payment->transition(
            PaymentStatus::FAILED,
            $this->correlationId,
            reason: 'Provider hard decline',
            failureCode: 'INSUFFICIENT_FUNDS',
            failureReason: 'Card has insufficient funds.',
        );

        $this->assertSame('INSUFFICIENT_FUNDS', $payment->failure_code);
        $this->assertSame('Card has insufficient funds.', $payment->failure_reason);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'failure_code' => 'INSUFFICIENT_FUNDS',
            'failure_reason' => 'Card has insufficient funds.',
        ]);
    }

    public function test_failure_fields_are_cleared_on_successful_transition(): void
    {
        $payment = $this->createPayment([
            'status' => PaymentStatus::REQUIRES_RECONCILIATION,
            'failure_code' => 'LEDGER_ERROR',
            'failure_reason' => 'Ledger write failed.',
        ]);

        $payment->transition(PaymentStatus::CAPTURED, $this->correlationId);

        $this->assertNull($payment->failure_code);
        $this->assertNull($payment->failure_reason);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'failure_code' => null,
            'failure_reason' => null,
        ]);
    }

    public function test_transition_updates_in_memory_model(): void
    {
        $payment = $this->createPayment();

        $payment->transition(PaymentStatus::PENDING_PROVIDER, $this->correlationId);

        $this->assertSame(PaymentStatus::PENDING_PROVIDER, $payment->status);
        $this->assertSame(1, $payment->version);
    }

    // -----------------------------------------------------------------------
    // Invalid transition
    // -----------------------------------------------------------------------

    public function test_invalid_transition_throws_exception(): void
    {
        $payment = $this->createPayment(); // status = created

        $this->expectException(InvalidPaymentTransitionException::class);
        $this->expectExceptionMessage("Invalid payment status transition from 'created' to 'captured'.");

        $payment->transition(PaymentStatus::CAPTURED, $this->correlationId);
    }

    public function test_transition_from_terminal_failed_throws_exception(): void
    {
        $payment = $this->createPayment(['status' => PaymentStatus::FAILED]);

        $this->expectException(InvalidPaymentTransitionException::class);

        $payment->transition(PaymentStatus::PENDING_PROVIDER, $this->correlationId);
    }

    public function test_invalid_transition_does_not_modify_database(): void
    {
        $payment = $this->createPayment();

        try {
            $payment->transition(PaymentStatus::CAPTURED, $this->correlationId);
        } catch (InvalidPaymentTransitionException) {
            // expected
        }

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => PaymentStatus::CREATED->value, 'version' => 0]);
        $this->assertDatabaseCount('payment_status_history', 0);
    }

    // -----------------------------------------------------------------------
    // Optimistic locking / concurrency conflict
    // -----------------------------------------------------------------------

    public function test_concurrency_conflict_throws_exception(): void
    {
        $payment = $this->createPayment(['version' => 0]);

        // Simulate a concurrent modification by bumping the DB version directly.
        DB::table('payments')->where('id', $payment->id)->update(['version' => 1]);

        $this->expectException(PaymentConcurrencyException::class);
        $this->expectExceptionMessageMatches('/expected version 0 was already modified/');

        // The in-memory model still thinks version = 0 — conflict must be detected.
        $payment->transition(PaymentStatus::PENDING_PROVIDER, $this->correlationId);
    }

    public function test_concurrency_conflict_does_not_create_history_record(): void
    {
        $payment = $this->createPayment(['version' => 0]);
        DB::table('payments')->where('id', $payment->id)->update(['version' => 1]);

        try {
            $payment->transition(PaymentStatus::PENDING_PROVIDER, $this->correlationId);
        } catch (PaymentConcurrencyException) {
            // expected
        }

        $this->assertDatabaseCount('payment_status_history', 0);
    }
}
