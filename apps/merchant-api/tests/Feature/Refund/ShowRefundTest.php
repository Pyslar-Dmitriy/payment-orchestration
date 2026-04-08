<?php

namespace Tests\Feature\Refund;

use App\Domain\Merchant\ApiKey;
use App\Domain\Merchant\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShowRefundTest extends TestCase
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

    private function fakeDomainRefund(string $refundId = 'ref-ulid-01234567890123'): void
    {
        Http::fake([
            '*' => Http::response([
                'refund_id' => $refundId,
                'payment_id' => '01knhswvgnty2g61yjgpqfk2tw',
                'status' => 'pending',
                'amount' => 1000,
                'currency' => 'USD',
                'correlation_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
                'created_at' => '2026-01-01T00:00:00+00:00',
                'updated_at' => '2026-01-01T00:00:00+00:00',
            ], 200),
        ]);
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_returns_refund_and_200(): void
    {
        $refundId = 'ref-ulid-01234567890123';
        $this->fakeDomainRefund($refundId);

        $response = $this->getJson("/api/v1/refunds/{$refundId}", $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'refund_id', 'payment_id', 'status', 'amount',
                'currency', 'correlation_id', 'created_at', 'updated_at',
            ])
            ->assertJsonFragment(['refund_id' => $refundId, 'status' => 'pending']);
    }

    public function test_passes_merchant_id_to_payment_domain(): void
    {
        $this->fakeDomainRefund();

        $this->getJson('/api/v1/refunds/ref-ulid-01234567890123', $this->authHeaders());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'merchant_id='.$this->merchant->id);
        });
    }

    public function test_passes_correlation_id_header_to_payment_domain(): void
    {
        $this->fakeDomainRefund();

        $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $this->getJson(
            '/api/v1/refunds/ref-ulid-01234567890123',
            $this->authHeaders(['X-Correlation-ID' => $correlationId])
        );

        Http::assertSent(function ($request) use ($correlationId) {
            return $request->header('X-Correlation-ID')[0] === $correlationId;
        });
    }

    // -----------------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------------

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/refunds/ref-ulid-01234567890123');

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // Not found / merchant isolation
    // -----------------------------------------------------------------------

    public function test_returns_404_when_refund_not_found(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Refund not found.'], 404)]);

        $response = $this->getJson('/api/v1/refunds/00000000000000000000000000', $this->authHeaders());

        $response->assertStatus(404)->assertJsonFragment(['message' => 'Refund not found.']);
    }

    public function test_returns_404_when_refund_belongs_to_another_merchant(): void
    {
        // payment-domain returns 404 when merchant_id does not match — merchant-api surfaces the same
        Http::fake(['*' => Http::response(['message' => 'Refund not found.'], 404)]);

        $response = $this->getJson('/api/v1/refunds/ref-ulid-01234567890123', $this->authHeaders());

        $response->assertStatus(404);
    }
}
