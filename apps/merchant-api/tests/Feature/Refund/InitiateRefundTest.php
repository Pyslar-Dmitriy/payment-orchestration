<?php

namespace Tests\Feature\Refund;

use App\Domain\Merchant\ApiKey;
use App\Domain\Merchant\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InitiateRefundTest extends TestCase
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
            'payment_id' => '01knhswvgnty2g61yjgpqfk2tw',
            'amount' => 1000,
        ], $overrides);
    }

    private function authHeaders(array $extra = []): array
    {
        return array_merge(['Authorization' => 'Bearer '.$this->apiKey], $extra);
    }

    private function fakeDomainSuccess(string $refundId = 'ref-ulid-01234567890123'): void
    {
        Http::fake([
            '*' => Http::response([
                'refund_id' => $refundId,
                'payment_id' => '01knhswvgnty2g61yjgpqfk2tw',
                'status' => 'pending',
                'amount' => 1000,
                'currency' => 'USD',
            ], 201),
        ]);
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_creates_refund_and_returns_201(): void
    {
        $this->fakeDomainSuccess();

        $response = $this->postJson('/api/v1/refunds', $this->validPayload(), $this->authHeaders());

        $response->assertStatus(201)
            ->assertJsonStructure(['refund_id', 'payment_id', 'status', 'amount', 'currency', 'correlation_id'])
            ->assertJsonFragment(['status' => 'pending']);
    }

    public function test_response_includes_correlation_id_from_request(): void
    {
        $this->fakeDomainSuccess();

        $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $response = $this->postJson(
            '/api/v1/refunds',
            $this->validPayload(),
            $this->authHeaders(['X-Correlation-ID' => $correlationId])
        );

        $response->assertStatus(201)->assertJsonFragment(['correlation_id' => $correlationId]);
    }

    public function test_passes_merchant_id_to_payment_domain(): void
    {
        $this->fakeDomainSuccess();

        $this->postJson('/api/v1/refunds', $this->validPayload(), $this->authHeaders());

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['merchant_id']) && $body['merchant_id'] === $this->merchant->id;
        });
    }

    // -----------------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------------

    public function test_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/refunds', $this->validPayload());

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    public function test_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/refunds', [], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_id', 'amount']);
    }

    public function test_validates_amount_must_be_positive(): void
    {
        $response = $this->postJson('/api/v1/refunds', $this->validPayload(['amount' => 0]), $this->authHeaders());

        $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_validates_payment_id_max_length(): void
    {
        $response = $this->postJson(
            '/api/v1/refunds',
            $this->validPayload(['payment_id' => str_repeat('a', 27)]),
            $this->authHeaders()
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['payment_id']);
    }

    // -----------------------------------------------------------------------
    // Payment not found (from payment-domain)
    // -----------------------------------------------------------------------

    public function test_returns_404_when_payment_not_found(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Payment not found.'], 404)]);

        $response = $this->postJson('/api/v1/refunds', $this->validPayload(), $this->authHeaders());

        $response->assertStatus(404)->assertJsonFragment(['message' => 'Payment not found.']);
    }

    // -----------------------------------------------------------------------
    // Business rule errors from payment-domain (422 pass-through)
    // -----------------------------------------------------------------------

    public function test_passes_through_422_when_payment_status_does_not_allow_refund(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'Payment status does not allow a refund.',
            'errors' => ['payment_id' => ['Only captured payments can be refunded.']],
        ], 422)]);

        $response = $this->postJson('/api/v1/refunds', $this->validPayload(), $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Payment status does not allow a refund.']);
    }

    public function test_passes_through_422_when_refund_amount_exceeds_payment(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'Refund amount exceeds the original payment amount.',
            'errors' => ['amount' => ['The refund amount must not exceed the original payment amount.']],
        ], 422)]);

        $response = $this->postJson('/api/v1/refunds', $this->validPayload(['amount' => 99999]), $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Refund amount exceeds the original payment amount.']);
    }

    // -----------------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------------

    public function test_idempotent_request_returns_cached_response(): void
    {
        $this->fakeDomainSuccess('ref-original-id-000000000');

        $headers = $this->authHeaders(['Idempotency-Key' => 'idem-refund-001']);

        $first = $this->postJson('/api/v1/refunds', $this->validPayload(), $headers);
        $first->assertStatus(201)->assertJsonFragment(['refund_id' => 'ref-original-id-000000000']);

        Http::fake(['*' => Http::response([
            'refund_id' => 'ref-different-id-00000000',
            'payment_id' => '01knhswvgnty2g61yjgpqfk2tw',
            'status' => 'pending',
            'amount' => 1000,
            'currency' => 'USD',
        ], 201)]);

        $second = $this->postJson('/api/v1/refunds', $this->validPayload(), $headers);
        $second->assertStatus(201)
            ->assertJsonFragment(['refund_id' => 'ref-original-id-000000000']);
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
                ->push([
                    'refund_id' => 'ref-merchant-1-000000000',
                    'payment_id' => '01knhswvgnty2g61yjgpqfk2tw',
                    'status' => 'pending',
                    'amount' => 1000,
                    'currency' => 'USD',
                ], 201)
                ->push([
                    'refund_id' => 'ref-merchant-2-000000000',
                    'payment_id' => '01knhswvgnty2g61yjgpqfk2tw',
                    'status' => 'pending',
                    'amount' => 1000,
                    'currency' => 'USD',
                ], 201),
        ]);

        $first = $this->postJson(
            '/api/v1/refunds',
            $this->validPayload(),
            $this->authHeaders(['Idempotency-Key' => 'same-key'])
        );

        $second = $this->postJson(
            '/api/v1/refunds',
            $this->validPayload(),
            ['Authorization' => 'Bearer '.$otherPlaintext, 'Idempotency-Key' => 'same-key']
        );

        $first->assertJsonFragment(['refund_id' => 'ref-merchant-1-000000000']);
        $second->assertJsonFragment(['refund_id' => 'ref-merchant-2-000000000']);
    }

    public function test_request_without_idempotency_key_is_not_cached(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'refund_id' => 'ref-001-00000000000000000',
                    'payment_id' => '01knhswvgnty2g61yjgpqfk2tw',
                    'status' => 'pending',
                    'amount' => 1000,
                    'currency' => 'USD',
                ], 201)
                ->push([
                    'refund_id' => 'ref-002-00000000000000000',
                    'payment_id' => '01knhswvgnty2g61yjgpqfk2tw',
                    'status' => 'pending',
                    'amount' => 1000,
                    'currency' => 'USD',
                ], 201),
        ]);

        $first = $this->postJson('/api/v1/refunds', $this->validPayload(), $this->authHeaders());
        $second = $this->postJson('/api/v1/refunds', $this->validPayload(), $this->authHeaders());

        $first->assertJsonFragment(['refund_id' => 'ref-001-00000000000000000']);
        $second->assertJsonFragment(['refund_id' => 'ref-002-00000000000000000']);
    }
}
