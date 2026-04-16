<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Infrastructure\Provider\Mock\Jobs\DeliverMockWebhookJob;
use App\Infrastructure\Provider\Mock\MockScenario;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Integration tests that exercise the full HTTP layer using the real MockProviderAdapter
 * (registered in AppServiceProvider). Each test switches the scenario via config()->set().
 */
class MockProviderIntegrationTest extends TestCase
{
    private string $paymentUuid = '550e8400-e29b-41d4-a716-446655440001';

    private string $refundUuid = '6ba7b810-9dad-11d1-80b4-00c04fd430c9';

    private string $correlationId = '7c9e6679-7425-40de-944b-e07fc1f90ae8';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('mock_provider.scenario', MockScenario::Success->value);
        config()->set('mock_provider.webhook_url', null);
    }

    // ── authorize: success ────────────────────────────────────────────────────

    public function test_authorize_success_returns_captured_sync_response(): void
    {
        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(200)
            ->assertExactJson([
                'provider_reference' => 'mock-'.$this->paymentUuid,
                'provider_status' => 'captured',
                'is_async' => false,
            ]);
    }

    // ── authorize: timeout ────────────────────────────────────────────────────

    public function test_authorize_timeout_returns_503(): void
    {
        config()->set('mock_provider.scenario', MockScenario::Timeout->value);

        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(503)
            ->assertJsonFragment(['message' => 'Provider temporarily unavailable.']);
    }

    // ── authorize: hard_failure ───────────────────────────────────────────────

    public function test_authorize_hard_failure_returns_422(): void
    {
        config()->set('mock_provider.scenario', MockScenario::HardFailure->value);

        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Provider declined the request.', 'provider_code' => 'mock_declined']);
    }

    // ── authorize: async_webhook ──────────────────────────────────────────────

    public function test_authorize_async_webhook_returns_pending_and_dispatches_job(): void
    {
        Queue::fake();
        config()->set('mock_provider.scenario', MockScenario::AsyncWebhook->value);

        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['provider_status' => 'pending', 'is_async' => true]);

        Queue::assertPushed(DeliverMockWebhookJob::class, 1);
    }

    // ── authorize: delayed_webhook ────────────────────────────────────────────

    public function test_authorize_delayed_webhook_returns_async_and_dispatches_delayed_job(): void
    {
        Queue::fake();
        config()->set('mock_provider.scenario', MockScenario::DelayedWebhook->value);
        config()->set('mock_provider.webhook_delay_seconds', 10);

        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['is_async' => true]);

        Queue::assertPushed(DeliverMockWebhookJob::class, 1);
    }

    // ── authorize: duplicate_webhook ──────────────────────────────────────────

    public function test_authorize_duplicate_webhook_dispatches_two_jobs(): void
    {
        Queue::fake();
        config()->set('mock_provider.scenario', MockScenario::DuplicateWebhook->value);

        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['is_async' => true]);

        Queue::assertPushed(DeliverMockWebhookJob::class, 2);
    }

    // ── authorize: out_of_order ───────────────────────────────────────────────

    public function test_authorize_out_of_order_dispatches_two_jobs_in_wrong_order(): void
    {
        Queue::fake();
        config()->set('mock_provider.scenario', MockScenario::OutOfOrder->value);

        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['is_async' => true]);

        Queue::assertPushed(DeliverMockWebhookJob::class, 2);
    }

    // ── refund ────────────────────────────────────────────────────────────────

    public function test_refund_success_returns_refunded(): void
    {
        $response = $this->postJson('/api/v1/provider/refund', [
            'refund_uuid' => $this->refundUuid,
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['provider_status' => 'refunded', 'is_async' => false]);
    }

    public function test_refund_timeout_returns_503(): void
    {
        config()->set('mock_provider.scenario', MockScenario::Timeout->value);

        $response = $this->postJson('/api/v1/provider/refund', [
            'refund_uuid' => $this->refundUuid,
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(503);
    }

    // ── payment status query ──────────────────────────────────────────────────

    public function test_payment_status_success_returns_captured(): void
    {
        $response = $this->getJson('/api/v1/provider/payments/'.$this->paymentUuid.'/status?provider_key=mock&correlation_id='.$this->correlationId);

        $response->assertStatus(200)
            ->assertJsonFragment(['provider_status' => 'captured', 'is_captured' => true]);
    }

    public function test_payment_status_timeout_returns_503(): void
    {
        config()->set('mock_provider.scenario', MockScenario::Timeout->value);

        $response = $this->getJson('/api/v1/provider/payments/'.$this->paymentUuid.'/status?provider_key=mock&correlation_id='.$this->correlationId);

        $response->assertStatus(503);
    }

    // ── refund status query ───────────────────────────────────────────────────

    public function test_refund_status_success_returns_refunded(): void
    {
        $response = $this->getJson('/api/v1/provider/refunds/'.$this->refundUuid.'/status?provider_key=mock&correlation_id='.$this->correlationId);

        $response->assertStatus(200)
            ->assertJsonFragment(['provider_status' => 'refunded', 'is_refunded' => true]);
    }
}
