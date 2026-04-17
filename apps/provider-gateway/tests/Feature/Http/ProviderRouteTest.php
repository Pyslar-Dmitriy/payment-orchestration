<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProviderRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset cache overrides so config defaults apply
        Cache::flush();

        config(['providers.routing' => [
            [
                'key' => 'mock',
                'currencies' => ['USD', 'EUR'],
                'countries' => ['US', 'DE'],
                'merchant_types' => [],
                'priority' => 10,
                'available' => true,
            ],
            [
                'key' => 'fallback',
                'currencies' => ['USD'],
                'countries' => ['US'],
                'merchant_types' => [],
                'priority' => 20,
                'available' => true,
            ],
        ]]);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_returns_matching_provider_key(): void
    {
        $response = $this->postJson('/api/v1/provider/route', [
            'currency' => 'USD',
            'country' => 'US',
        ]);

        $response->assertStatus(200)
            ->assertExactJson(['provider_key' => 'mock']);
    }

    public function test_accepts_optional_merchant_type(): void
    {
        $response = $this->postJson('/api/v1/provider/route', [
            'currency' => 'EUR',
            'country' => 'DE',
            'merchant_type' => 'retail',
        ]);

        $response->assertStatus(200)
            ->assertJson(['provider_key' => 'mock']);
    }

    public function test_returns_fallback_provider_when_primary_excluded(): void
    {
        $response = $this->postJson('/api/v1/provider/route', [
            'currency' => 'USD',
            'country' => 'US',
            'excluded_provider_keys' => ['mock'],
        ]);

        $response->assertStatus(200)
            ->assertExactJson(['provider_key' => 'fallback']);
    }

    public function test_currency_and_country_are_case_insensitive(): void
    {
        $response = $this->postJson('/api/v1/provider/route', [
            'currency' => 'usd',
            'country' => 'us',
        ]);

        $response->assertStatus(200)
            ->assertJson(['provider_key' => 'mock']);
    }

    // ── No provider available ─────────────────────────────────────────────────

    public function test_returns_422_when_no_provider_matches_currency(): void
    {
        $response = $this->postJson('/api/v1/provider/route', [
            'currency' => 'JPY',
            'country' => 'US',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'no_provider_available',
            ]);
    }

    public function test_returns_422_when_no_provider_matches_country(): void
    {
        $response = $this->postJson('/api/v1/provider/route', [
            'currency' => 'USD',
            'country' => 'JP',
        ]);

        $response->assertStatus(422)
            ->assertJson(['error' => 'no_provider_available']);
    }

    public function test_returns_422_when_all_providers_excluded(): void
    {
        $response = $this->postJson('/api/v1/provider/route', [
            'currency' => 'USD',
            'country' => 'US',
            'excluded_provider_keys' => ['mock', 'fallback'],
        ]);

        $response->assertStatus(422)
            ->assertJson(['error' => 'no_provider_available']);
    }

    public function test_returns_422_when_provider_marked_unavailable_via_cache(): void
    {
        Cache::put('provider_availability:mock', false);
        Cache::put('provider_availability:fallback', false);

        $response = $this->postJson('/api/v1/provider/route', [
            'currency' => 'USD',
            'country' => 'US',
        ]);

        $response->assertStatus(422)
            ->assertJson(['error' => 'no_provider_available']);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_returns_422_when_currency_missing(): void
    {
        $response = $this->postJson('/api/v1/provider/route', [
            'country' => 'US',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_returns_422_when_country_missing(): void
    {
        $response = $this->postJson('/api/v1/provider/route', [
            'currency' => 'USD',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country']);
    }

    public function test_returns_422_when_currency_wrong_length(): void
    {
        $response = $this->postJson('/api/v1/provider/route', [
            'currency' => 'USDD',
            'country' => 'US',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_returns_422_when_country_wrong_length(): void
    {
        $response = $this->postJson('/api/v1/provider/route', [
            'currency' => 'USD',
            'country' => 'USA',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country']);
    }
}
