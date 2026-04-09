<?php

namespace Tests\Feature\PaymentDomain;

use App\Domain\Merchant\ApiKey;
use App\Domain\Merchant\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentDomainResilienceTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear circuit breaker state between tests (cache is array driver in tests).
        Cache::flush();

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
            'external_order_id' => 'order-resilience-test',
            'customer_reference' => 'cust-resilience',
        ], $overrides);
    }

    private function authHeaders(array $extra = []): array
    {
        return array_merge(['Authorization' => 'Bearer '.$this->apiKey], $extra);
    }

    // -----------------------------------------------------------------------
    // Timeout
    // -----------------------------------------------------------------------

    public function test_returns_503_with_upstream_timeout_code_on_connection_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $response = $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders());

        $response->assertStatus(503)
            ->assertJsonFragment(['error_code' => 'UPSTREAM_TIMEOUT']);
    }

    // -----------------------------------------------------------------------
    // Retry
    // -----------------------------------------------------------------------

    public function test_retries_once_on_connection_error_and_succeeds(): void
    {
        $attempt = 0;

        Http::fake(function () use (&$attempt) {
            $attempt++;

            if ($attempt === 1) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['payment_id' => 'pay-retry-001', 'status' => 'initiated'], 201);
        });

        $response = $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders());

        $response->assertStatus(201)
            ->assertJsonFragment(['payment_id' => 'pay-retry-001']);

        $this->assertEquals(2, $attempt, 'Client should have made exactly 2 attempts (1 initial + 1 retry).');
    }

    public function test_does_not_retry_on_4xx_errors(): void
    {
        $attempt = 0;

        Http::fake(function () use (&$attempt) {
            $attempt++;

            return Http::response([
                'message' => 'Payment status does not allow a refund.',
                'errors' => ['payment_id' => ['Only captured payments can be refunded.']],
            ], 422);
        });

        $this->postJson('/api/v1/refunds', ['payment_id' => '01knhswvgnty2g61yjgpqfk2tw', 'amount' => 500], $this->authHeaders());

        $this->assertEquals(1, $attempt, 'Client must not retry on 4xx — only on connection errors.');
    }

    // -----------------------------------------------------------------------
    // Circuit breaker
    // -----------------------------------------------------------------------

    public function test_circuit_opens_after_five_consecutive_failures(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        // Each of the 5 requests exhausts retries and records one failure.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders())
                ->assertStatus(503);
        }

        // Circuit is now open — next request must short-circuit without calling payment-domain.
        Http::fake(['*' => Http::response(['payment_id' => 'should-not-reach', 'status' => 'initiated'], 201)]);

        $response = $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders());

        $response->assertStatus(503)
            ->assertJsonFragment(['error_code' => 'CIRCUIT_OPEN']);

        Http::assertNothingSent();
    }

    public function test_returns_503_immediately_when_circuit_is_already_open(): void
    {
        // Manually open the circuit to avoid needing 5 failing requests.
        Cache::put('circuit_breaker:payment_domain:open_until', now()->addSeconds(60)->timestamp, 120);

        Http::fake(['*' => Http::response(['payment_id' => 'should-not-reach', 'status' => 'initiated'], 201)]);

        $response = $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders());

        $response->assertStatus(503)
            ->assertJsonFragment(['error_code' => 'CIRCUIT_OPEN']);

        Http::assertNothingSent();
    }

    public function test_circuit_resets_after_successful_request(): void
    {
        // Http::fake() merges stubs rather than replacing them, so use a single fake
        // controlled by a mutable flag to switch between fail and succeed modes.
        $failMode = true;

        Http::fake(function () use (&$failMode) {
            if ($failMode) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['payment_id' => 'pay-reset-001', 'status' => 'initiated'], 201);
        });

        // Accumulate 4 failures (one below threshold).
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders())
                ->assertStatus(503);
        }

        // A successful request resets the failure counter.
        $failMode = false;

        $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders())
            ->assertStatus(201);

        // Circuit should still be closed after another failure now that the counter was reset.
        $failMode = true;

        $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders())
            ->assertStatus(503)
            ->assertJsonFragment(['error_code' => 'UPSTREAM_TIMEOUT']);
    }

    // -----------------------------------------------------------------------
    // Error mapping
    // -----------------------------------------------------------------------

    public function test_maps_422_from_payment_domain_to_merchant_facing_422(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'Payment status does not allow a refund.',
            'errors' => ['payment_id' => ['Only captured payments can be refunded.']],
        ], 422)]);

        $response = $this->postJson(
            '/api/v1/refunds',
            ['payment_id' => '01knhswvgnty2g61yjgpqfk2tw', 'amount' => 500],
            $this->authHeaders()
        );

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Payment status does not allow a refund.']);
    }

    public function test_maps_409_from_payment_domain_to_merchant_facing_409(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Duplicate payment.'], 409)]);

        $response = $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders());

        $response->assertStatus(409)
            ->assertJsonFragment(['error_code' => 'CONFLICT']);
    }

    public function test_maps_500_from_payment_domain_to_503(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Internal server error.'], 500)]);

        $response = $this->postJson('/api/v1/payments', $this->validPayload(), $this->authHeaders());

        $response->assertStatus(503)
            ->assertJsonFragment(['error_code' => 'UPSTREAM_ERROR']);
    }
}
