<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Provider;

use App\Domain\Provider\DTO\RoutingRequest;
use App\Domain\Provider\Exception\NoProviderAvailableException;
use App\Domain\Provider\ProviderRouter;
use App\Domain\Provider\ProviderRoutingConfig;
use PHPUnit\Framework\TestCase;

class ProviderRouterTest extends TestCase
{
    private ProviderRouter $router;

    protected function setUp(): void
    {
        $this->router = new ProviderRouter;
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_returns_matching_provider(): void
    {
        $configs = [
            $this->config('mock', ['USD'], ['US'], [], 10, true),
        ];

        $result = $this->router->route($configs, new RoutingRequest('USD', 'US', null));

        $this->assertSame('mock', $result);
    }

    public function test_currency_comparison_is_case_insensitive(): void
    {
        $configs = [
            $this->config('mock', ['USD'], ['US'], [], 10, true),
        ];

        $result = $this->router->route($configs, new RoutingRequest('usd', 'us', null));

        $this->assertSame('mock', $result);
    }

    public function test_selects_lower_priority_first(): void
    {
        $configs = [
            $this->config('provider-b', ['USD'], ['US'], [], 20, true),
            $this->config('provider-a', ['USD'], ['US'], [], 5, true),
        ];

        $result = $this->router->route($configs, new RoutingRequest('USD', 'US', null));

        $this->assertSame('provider-a', $result);
    }

    public function test_selects_next_when_first_excluded(): void
    {
        $configs = [
            $this->config('provider-a', ['USD'], ['US'], [], 5, true),
            $this->config('provider-b', ['USD'], ['US'], [], 10, true),
        ];

        $result = $this->router->route(
            $configs,
            new RoutingRequest('USD', 'US', null, excludedProviderKeys: ['provider-a']),
        );

        $this->assertSame('provider-b', $result);
    }

    public function test_provider_with_empty_merchant_types_accepts_all_requests(): void
    {
        $configs = [
            $this->config('mock', ['USD'], ['US'], [], 10, true),
        ];

        // Request with no merchant_type should still match
        $this->assertSame('mock', $this->router->route($configs, new RoutingRequest('USD', 'US', null)));
        // Request with any merchant_type should match
        $this->assertSame('mock', $this->router->route($configs, new RoutingRequest('USD', 'US', 'retail')));
    }

    public function test_provider_with_merchant_types_matches_specific_type(): void
    {
        $configs = [
            $this->config('mock', ['USD'], ['US'], ['retail', 'ecommerce'], 10, true),
        ];

        $result = $this->router->route($configs, new RoutingRequest('USD', 'US', 'retail'));

        $this->assertSame('mock', $result);
    }

    // ── Filtering: available = false ──────────────────────────────────────────

    public function test_unavailable_provider_is_excluded(): void
    {
        $configs = [
            $this->config('provider-a', ['USD'], ['US'], [], 5, false),
            $this->config('provider-b', ['USD'], ['US'], [], 10, true),
        ];

        $result = $this->router->route($configs, new RoutingRequest('USD', 'US', null));

        $this->assertSame('provider-b', $result);
    }

    public function test_throws_when_only_provider_is_unavailable(): void
    {
        $this->expectException(NoProviderAvailableException::class);

        $configs = [
            $this->config('mock', ['USD'], ['US'], [], 10, false),
        ];

        $this->router->route($configs, new RoutingRequest('USD', 'US', null));
    }

    // ── Filtering: currency ───────────────────────────────────────────────────

    public function test_throws_when_currency_not_supported(): void
    {
        $this->expectException(NoProviderAvailableException::class);

        $configs = [
            $this->config('mock', ['EUR'], ['US'], [], 10, true),
        ];

        $this->router->route($configs, new RoutingRequest('USD', 'US', null));
    }

    // ── Filtering: country ────────────────────────────────────────────────────

    public function test_throws_when_country_not_supported(): void
    {
        $this->expectException(NoProviderAvailableException::class);

        $configs = [
            $this->config('mock', ['USD'], ['GB'], [], 10, true),
        ];

        $this->router->route($configs, new RoutingRequest('USD', 'US', null));
    }

    // ── Filtering: merchant_types ─────────────────────────────────────────────

    public function test_throws_when_provider_requires_merchant_type_but_none_provided(): void
    {
        $this->expectException(NoProviderAvailableException::class);

        $configs = [
            $this->config('mock', ['USD'], ['US'], ['retail'], 10, true),
        ];

        $this->router->route($configs, new RoutingRequest('USD', 'US', null));
    }

    public function test_throws_when_merchant_type_not_in_provider_list(): void
    {
        $this->expectException(NoProviderAvailableException::class);

        $configs = [
            $this->config('mock', ['USD'], ['US'], ['retail'], 10, true),
        ];

        $this->router->route($configs, new RoutingRequest('USD', 'US', 'gambling'));
    }

    // ── No candidates ─────────────────────────────────────────────────────────

    public function test_throws_when_no_providers_configured(): void
    {
        $this->expectException(NoProviderAvailableException::class);

        $this->router->route([], new RoutingRequest('USD', 'US', null));
    }

    public function test_throws_when_all_candidates_excluded(): void
    {
        $this->expectException(NoProviderAvailableException::class);

        $configs = [
            $this->config('mock', ['USD'], ['US'], [], 10, true),
        ];

        $this->router->route(
            $configs,
            new RoutingRequest('USD', 'US', null, excludedProviderKeys: ['mock']),
        );
    }

    public function test_exception_contains_routing_parameters(): void
    {
        try {
            $this->router->route([], new RoutingRequest('USD', 'US', 'retail'));
            $this->fail('Expected NoProviderAvailableException');
        } catch (NoProviderAvailableException $e) {
            $this->assertSame('USD', $e->currency);
            $this->assertSame('US', $e->country);
            $this->assertSame('retail', $e->merchantType);
        }
    }

    // ── Multi-provider priority ordering ─────────────────────────────────────

    public function test_multiple_matching_providers_ordered_by_priority(): void
    {
        $configs = [
            $this->config('c', ['USD'], ['US'], [], 30, true),
            $this->config('a', ['USD'], ['US'], [], 5, true),
            $this->config('b', ['USD'], ['US'], [], 15, true),
        ];

        $this->assertSame('a', $this->router->route($configs, new RoutingRequest('USD', 'US', null)));
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function config(
        string $key,
        array $currencies,
        array $countries,
        array $merchantTypes,
        int $priority,
        bool $available,
    ): ProviderRoutingConfig {
        return new ProviderRoutingConfig(
            providerKey: $key,
            currencies: $currencies,
            countries: $countries,
            merchantTypes: $merchantTypes,
            priority: $priority,
            available: $available,
        );
    }
}
