<?php

namespace Tests\Feature;

use App\Domain\Merchant\ApiKey;
use App\Domain\Merchant\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RateLimitTest extends TestCase
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

        // Use a low limit so tests can exhaust it without many requests
        config(['services.rate_limit.per_minute' => 3]);

        Http::fake(['*' => Http::response(['payment_id' => 'test-pay-id', 'status' => 'initiated'], 201)]);
    }

    protected function tearDown(): void
    {
        // Clear rate limit state using the sha1(token) key format the limiter uses
        RateLimiter::clear(sha1($this->apiKey));
        parent::tearDown();
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->apiKey];
    }

    private function makeAuthenticatedRequest(): TestResponse
    {
        return $this->getJson('/api/v1/merchants/me', $this->authHeaders());
    }

    // -----------------------------------------------------------------------
    // Happy path: requests within the limit succeed
    // -----------------------------------------------------------------------

    public function test_requests_within_limit_succeed(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->makeAuthenticatedRequest()->assertStatus(200);
        }
    }

    // -----------------------------------------------------------------------
    // Rate limit enforcement
    // -----------------------------------------------------------------------

    public function test_returns_429_when_limit_exceeded(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->makeAuthenticatedRequest();
        }

        $response = $this->makeAuthenticatedRequest();

        $response->assertStatus(429);
    }

    public function test_429_response_has_stable_shape(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->makeAuthenticatedRequest();
        }

        $response = $this->makeAuthenticatedRequest();

        $response->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after'])
            ->assertJsonFragment(['message' => 'Too many requests.']);

        $this->assertIsInt($response->json('retry_after'));
        $this->assertGreaterThan(0, $response->json('retry_after'));
    }

    // -----------------------------------------------------------------------
    // Per-key isolation
    // -----------------------------------------------------------------------

    public function test_rate_limit_is_scoped_per_api_key(): void
    {
        $otherMerchant = Merchant::factory()->create();
        $otherPlaintext = ApiKey::generatePlaintext();
        ApiKey::factory()->withPlaintext($otherPlaintext)->create([
            'merchant_id' => $otherMerchant->id,
        ]);

        // Exhaust the first key's limit
        for ($i = 0; $i < 3; $i++) {
            $this->makeAuthenticatedRequest();
        }
        $this->makeAuthenticatedRequest()->assertStatus(429);

        // Second key should still succeed — different sha1 key in the limiter
        $this->getJson('/api/v1/merchants/me', [
            'Authorization' => 'Bearer '.$otherPlaintext,
        ])->assertStatus(200);
    }

    // -----------------------------------------------------------------------
    // Logging
    // -----------------------------------------------------------------------

    public function test_rate_limit_violation_is_logged(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Rate limit exceeded.'
                    && isset($context['method'])
                    && isset($context['path'])
                    && isset($context['retry_after']);
            });

        // Also allow other log calls (shareContext, etc.)
        Log::shouldReceive('shareContext')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        for ($i = 0; $i < 3; $i++) {
            $this->makeAuthenticatedRequest();
        }

        $this->makeAuthenticatedRequest();
    }
}
