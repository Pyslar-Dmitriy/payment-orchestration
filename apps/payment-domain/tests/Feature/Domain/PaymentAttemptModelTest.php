<?php

namespace Tests\Feature\Domain;

use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentAttempt;
use App\Domain\Payment\PaymentAttemptStatus;
use App\Domain\Payment\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentAttemptModelTest extends TestCase
{
    use RefreshDatabase;

    private function createPayment(): Payment
    {
        return Payment::create([
            'merchant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'external_reference' => 'order-001',
            'idempotency_key' => 'idem-attempt-test-001',
            'amount' => 2000,
            'currency' => 'USD',
            'status' => PaymentStatus::CREATED,
            'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        ]);
    }

    public function test_can_create_a_payment_attempt(): void
    {
        $payment = $this->createPayment();

        $attempt = PaymentAttempt::create([
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'provider_id' => 'stripe',
            'status' => PaymentAttemptStatus::PENDING,
            'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        ]);

        $this->assertDatabaseHas('payment_attempts', [
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'provider_id' => 'stripe',
            'status' => PaymentAttemptStatus::PENDING->value,
        ]);

        $this->assertNotNull($attempt->id);
    }

    public function test_ulid_is_auto_generated(): void
    {
        $payment = $this->createPayment();

        $attempt = PaymentAttempt::create([
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'provider_id' => 'stripe',
            'status' => PaymentAttemptStatus::PENDING,
            'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        ]);

        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $attempt->id);
    }

    public function test_status_is_cast_to_enum(): void
    {
        $payment = $this->createPayment();

        $attempt = PaymentAttempt::create([
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'provider_id' => 'stripe',
            'status' => 'succeeded',
            'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        ]);

        $this->assertInstanceOf(PaymentAttemptStatus::class, $attempt->fresh()->status);
        $this->assertSame(PaymentAttemptStatus::SUCCEEDED, $attempt->fresh()->status);
    }

    public function test_provider_response_is_cast_to_array(): void
    {
        $payment = $this->createPayment();

        $attempt = PaymentAttempt::create([
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'provider_id' => 'stripe',
            'status' => PaymentAttemptStatus::FAILED,
            'provider_response' => ['error' => 'card_declined', 'code' => 'insufficient_funds'],
            'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        ]);

        $this->assertIsArray($attempt->fresh()->provider_response);
        $this->assertSame('card_declined', $attempt->fresh()->provider_response['error']);
    }

    public function test_payment_relationship_resolves(): void
    {
        $payment = $this->createPayment();

        $attempt = PaymentAttempt::create([
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'provider_id' => 'stripe',
            'status' => PaymentAttemptStatus::PENDING,
            'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        ]);

        $this->assertTrue($attempt->payment()->exists());
        $this->assertSame($payment->id, $attempt->payment->id);
    }

    public function test_payment_has_attempts_relationship(): void
    {
        $payment = $this->createPayment();

        PaymentAttempt::create([
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'provider_id' => 'stripe',
            'status' => PaymentAttemptStatus::FAILED,
            'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        ]);

        PaymentAttempt::create([
            'payment_id' => $payment->id,
            'attempt_number' => 2,
            'provider_id' => 'adyen',
            'status' => PaymentAttemptStatus::SUCCEEDED,
            'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        ]);

        $this->assertCount(2, $payment->attempts);
    }
}
