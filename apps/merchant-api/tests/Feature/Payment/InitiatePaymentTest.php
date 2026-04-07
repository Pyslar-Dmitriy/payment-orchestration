<?php

namespace Tests\Feature\Payment;

use App\Domain\Merchant\ApiKey;
use App\Domain\Merchant\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InitiatePaymentTest extends TestCase
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 1000,
            'currency' => 'USD',
            'external_order_id' => 'order-abc-123',
            'customer_reference' => 'cust-456',
            'payment_method_token' => 'pm_test_token',
            'metadata' => ['source' => 'web'],
        ], $overrides);
    }

    private function authHeaders(array $extra = []): array
    {
        return array_merge(['Authorization' => 'Bearer '.$this->apiKey], $extra);
    }

    private function fakeDomainSuccess(string $paymentId = 'pay-ulid-01234567890'): void
    {
        Http::fake([
            '*' => Http::response([
                'payment_id' => $paymentId,
                'status' => 'initiated',
            ], 201),
        ]);
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_creates_payment_and_returns_201(): void
    {
        $this->fakeDomainSuccess();

        $response = $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders());

        $response->assertStatus(201)
            ->assertJsonStructure(['payment_id', 'status', 'correlation_id'])
            ->assertJsonFragment(['status' => 'initiated']);
    }

    public function test_response_includes_correlation_id_from_request(): void
    {
        $this->fakeDomainSuccess();

        $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $response = $this->postJson(
            '/api/v1/payments',
            $this->validPayload(),
            $this->authHeaders(['X-Correlation-ID' => $correlationId])
        );

        $response->assertStatus(201)
            ->assertJsonFragment(['correlation_id' => $correlationId]);
    }

    // -----------------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------------

    public function test_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload());

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    public function test_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/payments', [], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'currency', 'external_order_id']);
    }

    public function test_validates_amount_must_be_positive(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload(['amount' => 0]), $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_validates_currency_must_be_three_characters(): void
    {
        $response = $this->postJson('/api/v1/payments', $this->validPayload(['currency' => 'US']), $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    // -----------------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------------

    public function test_idempotent_request_returns_cached_response(): void
    {
        $this->fakeDomainSuccess('pay-original-id');

        $headers = $this->authHeaders(['Idempotency-Key' => 'idem-key-001']);

        $first = $this->postJson('/api/v1/payments', $this->validPayload(), $headers);
        $first->assertStatus(201)->assertJsonFragment(['payment_id' => 'pay-original-id']);

        // Reset fake — if domain is called again it would return a different ID
        Http::fake(['*' => Http::response(['payment_id' => 'pay-different-id', 'status' => 'initiated'], 201)]);

        $second = $this->postJson('/api/v1/payments', $this->validPayload(), $headers);
        $second->assertStatus(201)
            ->assertJsonFragment(['payment_id' => 'pay-original-id']);
    }

    public function test_different_idempotency_keys_create_separate_payments(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['payment_id' => 'pay-001', 'status' => 'initiated'], 201)
                ->push(['payment_id' => 'pay-002', 'status' => 'initiated'], 201),
        ]);

        $first = $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders(['Idempotency-Key' => 'key-A']));
        $second = $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders(['Idempotency-Key' => 'key-B']));

        $first->assertJsonFragment(['payment_id' => 'pay-001']);
        $second->assertJsonFragment(['payment_id' => 'pay-002']);
    }

    public function test_request_without_idempotency_key_is_not_cached(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['payment_id' => 'pay-001', 'status' => 'initiated'], 201)
                ->push(['payment_id' => 'pay-002', 'status' => 'initiated'], 201),
        ]);

        $first = $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders());
        $second = $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders());

        // Both go to payment-domain since no idempotency key was provided
        $first->assertJsonFragment(['payment_id' => 'pay-001']);
        $second->assertJsonFragment(['payment_id' => 'pay-002']);
    }

    public function test_idempotency_key_is_scoped_per_merchant(): void
    {
        $otherMerchant = Merchant::factory()->create();
        $otherPlaintext = ApiKey::generatePlaintext();
        ApiKey::factory()->withPlaintext($otherPlaintext)->create([
            'merchant_id' => $otherMerchant->id,
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['payment_id' => 'pay-merchant-1', 'status' => 'initiated'], 201)
                ->push(['payment_id' => 'pay-merchant-2', 'status' => 'initiated'], 201),
        ]);

        $first = $this->postJson(
            '/api/v1/payments',
            $this->validPayload(),
            $this->authHeaders(['Idempotency-Key' => 'same-key'])
        );

        $second = $this->postJson(
            '/api/v1/payments',
            $this->validPayload(),
            ['Authorization' => 'Bearer '.$otherPlaintext, 'Idempotency-Key' => 'same-key']
        );

        $first->assertJsonFragment(['payment_id' => 'pay-merchant-1']);
        $second->assertJsonFragment(['payment_id' => 'pay-merchant-2']);
    }
}