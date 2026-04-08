<?php

namespace Tests\Feature\Refund;

use App\Domain\Payment\Payment;
use App\Domain\Refund\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowRefundTest extends TestCase
{
    use RefreshDatabase;

    private string $merchantId = '550e8400-e29b-41d4-a716-446655440000';

    private string $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    private function createPayment(array $overrides = []): Payment
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

    private function createRefund(string $paymentId, array $overrides = []): Refund
    {
        return Refund::create(array_merge([
            'payment_id' => $paymentId,
            'merchant_id' => $this->merchantId,
            'amount' => 1000,
            'currency' => 'USD',
            'status' => 'pending',
            'correlation_id' => $this->correlationId,
        ], $overrides));
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_returns_refund_and_200(): void
    {
        $payment = $this->createPayment();
        $refund = $this->createRefund($payment->id);

        $response = $this->getJson("/api/v1/refunds/{$refund->id}?merchant_id={$this->merchantId}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'refund_id', 'payment_id', 'status', 'amount',
                'currency', 'correlation_id', 'created_at', 'updated_at',
            ])
            ->assertJsonFragment([
                'refund_id' => $refund->id,
                'payment_id' => $payment->id,
                'status' => 'pending',
                'amount' => 1000,
                'currency' => 'USD',
                'correlation_id' => $this->correlationId,
            ]);
    }

    public function test_response_includes_iso8601_timestamps(): void
    {
        $payment = $this->createPayment();
        $refund = $this->createRefund($payment->id);

        $response = $this->getJson("/api/v1/refunds/{$refund->id}?merchant_id={$this->merchantId}");

        $response->assertStatus(200);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $response->json('created_at')
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $response->json('updated_at')
        );
    }

    // -----------------------------------------------------------------------
    // Not found / merchant isolation
    // -----------------------------------------------------------------------

    public function test_returns_404_when_refund_does_not_exist(): void
    {
        $response = $this->getJson("/api/v1/refunds/00000000000000000000000000?merchant_id={$this->merchantId}");

        $response->assertStatus(404)->assertJsonFragment(['message' => 'Refund not found.']);
    }

    public function test_returns_404_when_refund_belongs_to_another_merchant(): void
    {
        $payment = $this->createPayment(['merchant_id' => '550e8400-e29b-41d4-a716-000000000001']);
        $refund = $this->createRefund($payment->id, ['merchant_id' => '550e8400-e29b-41d4-a716-000000000001']);

        $response = $this->getJson("/api/v1/refunds/{$refund->id}?merchant_id={$this->merchantId}");

        $response->assertStatus(404)->assertJsonFragment(['message' => 'Refund not found.']);
    }
}
