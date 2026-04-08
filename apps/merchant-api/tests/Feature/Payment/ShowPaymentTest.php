<?php

namespace Tests\Feature\Payment;

use App\Domain\Merchant\ApiKey;
use App\Domain\Merchant\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShowPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $plaintext = ApiKey::generatePlaintext();
        ApiKey::factory()->withPlaintext($plaintext)->create([
            'merchant_id' => $this->merchant->id,
        ]);
        $this->apiKey = $plaintext;
    }

    private function authHeaders(array $extra = []): array
    {
        return array_merge(['Authorization' => 'Bearer '.$this->apiKey], $extra);
    }

    private function fakeDomainPayment(string $paymentId = 'pay-ulid-01234567890'): void
    {
        Http::fake([
            '*' => Http::response([
                'payment_id' => $paymentId,
                'status' => 'initiated',
                'amount' => 1000,
                'currency' => 'USD',
                'provider_reference' => null,
                'failure_reason' => null,
                'created_at' => '2026-01-01T00:00:00+00:00',
                'updated_at' => '2026-01-01T00:00:00+00:00',
            ], 200),
        ]);
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_returns_payment_and_200(): void
    {
        $paymentId = 'pay-ulid-01234567890';
        $this->fakeDomainPayment($paymentId);

        $response = $this->getJson("/api/v1/payments/{$paymentId}", $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'payment_id', 'status', 'amount', 'currency',
                'provider_reference', 'failure_reason',
                'created_at', 'updated_at', 'correlation_id',
            ])
            ->assertJsonFragment(['payment_id' => $paymentId, 'status' => 'initiated']);
    }

    public function test_response_includes_correlation_id_from_request(): void
    {
        $this->fakeDomainPayment();

        $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $response = $this->getJson(
            '/api/v1/payments/pay-ulid-01234567890',
            $this->authHeaders(['X-Correlation-ID' => $correlationId])
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['correlation_id' => $correlationId]);
    }

    public function test_passes_merchant_id_to_payment_domain(): void
    {
        $this->fakeDomainPayment();

        $this->getJson('/api/v1/payments/pay-ulid-01234567890', $this->authHeaders());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'merchant_id='.$this->merchant->id);
        });
    }

    // -----------------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------------

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/payments/pay-ulid-01234567890');

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // Not found / merchant isolation
    // -----------------------------------------------------------------------

    public function test_returns_404_when_payment_not_found(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Payment not found.'], 404)]);

        $response = $this->getJson('/api/v1/payments/pay-ulid-01234567890', $this->authHeaders());

        $response->assertStatus(404);
    }

    public function test_returns_404_when_payment_belongs_to_another_merchant(): void
    {
        // payment-domain returns 404 when merchant_id does not match — merchant-api surfaces the same
        Http::fake(['*' => Http::response(['message' => 'Payment not found.'], 404)]);

        $response = $this->getJson('/api/v1/payments/pay-ulid-01234567890', $this->authHeaders());

        $response->assertStatus(404);
    }
}
