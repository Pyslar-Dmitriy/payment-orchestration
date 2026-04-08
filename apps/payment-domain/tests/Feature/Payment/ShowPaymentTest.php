<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\PaymentStatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowPaymentTest extends TestCase
{
    use RefreshDatabase;

    private string $merchantId = '550e8400-e29b-41d4-a716-446655440000';

    private string $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    private function createPayment(array $overrides = []): string
    {
        $response = $this->postJson('/api/v1/payments', array_merge([
            'merchant_id' => $this->merchantId,
            'amount' => 1000,
            'currency' => 'USD',
            'external_reference' => 'order-abc-123',
            'correlation_id' => $this->correlationId,
        ], $overrides));

        return $response->json('payment_id');
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_returns_payment_for_owner(): void
    {
        $paymentId = $this->createPayment();

        $response = $this->getJson("/api/v1/payments/{$paymentId}?merchant_id={$this->merchantId}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'payment_id', 'status', 'amount', 'currency',
                'provider_reference', 'failure_reason', 'created_at', 'updated_at',
            ])
            ->assertJsonFragment([
                'payment_id' => $paymentId,
                'status' => 'initiated',
                'amount' => 1000,
                'currency' => 'USD',
                'provider_reference' => null,
                'failure_reason' => null,
            ]);
    }

    public function test_returns_last_failure_reason(): void
    {
        $paymentId = $this->createPayment();

        PaymentStatusHistory::create([
            'payment_id' => $paymentId,
            'from_status' => 'authorizing',
            'to_status' => 'failed',
            'reason' => 'Insufficient funds',
            'correlation_id' => $this->correlationId,
        ]);

        $response = $this->getJson("/api/v1/payments/{$paymentId}?merchant_id={$this->merchantId}");

        $response->assertStatus(200)
            ->assertJsonFragment(['failure_reason' => 'Insufficient funds']);
    }

    public function test_returns_most_recent_failure_reason_when_multiple_exist(): void
    {
        $paymentId = $this->createPayment();

        PaymentStatusHistory::create([
            'payment_id' => $paymentId,
            'from_status' => 'authorizing',
            'to_status' => 'failed',
            'reason' => 'Card declined',
            'correlation_id' => $this->correlationId,
        ]);

        PaymentStatusHistory::create([
            'payment_id' => $paymentId,
            'from_status' => 'authorizing',
            'to_status' => 'failed',
            'reason' => 'Timeout',
            'correlation_id' => $this->correlationId,
        ]);

        $response = $this->getJson("/api/v1/payments/{$paymentId}?merchant_id={$this->merchantId}");

        $response->assertStatus(200)
            ->assertJsonFragment(['failure_reason' => 'Timeout']);
    }

    // -----------------------------------------------------------------------
    // Not found / merchant isolation
    // -----------------------------------------------------------------------

    public function test_returns_404_for_unknown_payment_id(): void
    {
        $response = $this->getJson("/api/v1/payments/01jxxxxxxxxxxxxxxxxxxxxxxxxx?merchant_id={$this->merchantId}");

        $response->assertStatus(404);
    }

    public function test_returns_404_when_payment_belongs_to_another_merchant(): void
    {
        $paymentId = $this->createPayment();

        $otherMerchantId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $response = $this->getJson("/api/v1/payments/{$paymentId}?merchant_id={$otherMerchantId}");

        $response->assertStatus(404);
    }
}
