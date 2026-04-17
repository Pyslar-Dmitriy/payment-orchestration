<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProviderAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config(['providers.routing' => [
            [
                'key' => 'mock',
                'currencies' => ['USD'],
                'countries' => ['US'],
                'merchant_types' => [],
                'priority' => 10,
                'available' => true,
            ],
        ]]);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_disables_provider_at_runtime(): void
    {
        $response = $this->patchJson('/api/internal/providers/mock/availability', [
            'available' => false,
        ], ['REMOTE_ADDR' => '127.0.0.1']);

        $response->assertStatus(200)
            ->assertExactJson([
                'provider_key' => 'mock',
                'available' => false,
            ]);

        $this->assertFalse(Cache::get('provider_availability:mock'));
    }

    public function test_enables_provider_at_runtime(): void
    {
        Cache::put('provider_availability:mock', false);

        $response = $this->patchJson('/api/internal/providers/mock/availability', [
            'available' => true,
        ], ['REMOTE_ADDR' => '127.0.0.1']);

        $response->assertStatus(200)
            ->assertExactJson([
                'provider_key' => 'mock',
                'available' => true,
            ]);

        $this->assertTrue(Cache::get('provider_availability:mock'));
    }

    public function test_disabled_provider_is_excluded_from_routing(): void
    {
        $this->patchJson('/api/internal/providers/mock/availability', [
            'available' => false,
        ], ['REMOTE_ADDR' => '127.0.0.1']);

        $routeResponse = $this->postJson('/api/v1/provider/route', [
            'currency' => 'USD',
            'country' => 'US',
        ]);

        $routeResponse->assertStatus(422)
            ->assertJson(['error' => 'no_provider_available']);
    }

    // ── Not found ─────────────────────────────────────────────────────────────

    public function test_returns_404_for_unconfigured_provider_key(): void
    {
        $response = $this->patchJson('/api/internal/providers/stripe/availability', [
            'available' => false,
        ], ['REMOTE_ADDR' => '127.0.0.1']);

        $response->assertStatus(404)
            ->assertJson(['message' => "Provider 'stripe' is not configured."]);
    }

    // ── Internal network restriction ──────────────────────────────────────────

    public function test_returns_403_from_public_ip(): void
    {
        $response = $this->patchJson('/api/internal/providers/mock/availability', [
            'available' => false,
        ], ['REMOTE_ADDR' => '8.8.8.8']);

        $response->assertStatus(403);
    }

    public function test_allows_request_from_docker_bridge_range(): void
    {
        $response = $this->patchJson('/api/internal/providers/mock/availability', [
            'available' => false,
        ], ['REMOTE_ADDR' => '172.17.0.1']);

        $response->assertStatus(200);
    }

    public function test_allows_request_from_private_class_a(): void
    {
        $response = $this->patchJson('/api/internal/providers/mock/availability', [
            'available' => true,
        ], ['REMOTE_ADDR' => '10.0.0.5']);

        $response->assertStatus(200);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_returns_422_when_available_field_missing(): void
    {
        $response = $this->patchJson('/api/internal/providers/mock/availability', [
        ], ['REMOTE_ADDR' => '127.0.0.1']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['available']);
    }

    public function test_returns_422_when_available_field_is_not_boolean(): void
    {
        $response = $this->patchJson('/api/internal/providers/mock/availability', [
            'available' => 'yes',
        ], ['REMOTE_ADDR' => '127.0.0.1']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['available']);
    }
}
