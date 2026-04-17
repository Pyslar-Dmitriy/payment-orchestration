<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Routing;

use App\Domain\Provider\ProviderRoutingConfig;
use App\Domain\Provider\ProviderRoutingConfigRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Reads provider routing configs from config('providers.routing') and merges
 * runtime availability overrides stored in the cache.
 *
 * Cache key format: provider_availability:{providerKey}
 * Cache TTL: 24 hours (operators can clear it with `php artisan cache:clear`).
 */
final class ConfigProviderRoutingConfigRepository implements ProviderRoutingConfigRepositoryInterface
{
    private const CACHE_KEY_PREFIX = 'provider_availability:';

    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(private readonly CacheRepository $cache) {}

    public function all(): array
    {
        return array_map(
            fn (array $raw): ProviderRoutingConfig => $this->hydrate($raw),
            config('providers.routing', []),
        );
    }

    public function find(string $providerKey): ?ProviderRoutingConfig
    {
        foreach (config('providers.routing', []) as $raw) {
            if ($raw['key'] === $providerKey) {
                return $this->hydrate($raw);
            }
        }

        return null;
    }

    public function setAvailability(string $providerKey, bool $available): void
    {
        $this->cache->put(
            self::CACHE_KEY_PREFIX.$providerKey,
            $available,
            self::CACHE_TTL_SECONDS,
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function hydrate(array $raw): ProviderRoutingConfig
    {
        $key = $raw['key'];
        $configAvailable = (bool) ($raw['available'] ?? true);

        $available = $this->cache->has(self::CACHE_KEY_PREFIX.$key)
            ? (bool) $this->cache->get(self::CACHE_KEY_PREFIX.$key)
            : $configAvailable;

        return new ProviderRoutingConfig(
            providerKey: $key,
            currencies: array_map('strtoupper', $raw['currencies'] ?? []),
            countries: array_map('strtoupper', $raw['countries'] ?? []),
            merchantTypes: $raw['merchant_types'] ?? [],
            priority: (int) ($raw['priority'] ?? 0),
            available: $available,
        );
    }
}
