<?php

declare(strict_types=1);

namespace App\Domain\Provider;

/**
 * Provides the current routing configuration for all registered providers.
 *
 * Implementations must merge static config with any runtime availability
 * overrides so that ProviderRouter always sees the live state.
 */
interface ProviderRoutingConfigRepositoryInterface
{
    /**
     * Returns all provider routing configs with current availability state applied.
     *
     * @return ProviderRoutingConfig[]
     */
    public function all(): array;

    /**
     * Returns routing config for the given provider key, or null if not configured.
     */
    public function find(string $providerKey): ?ProviderRoutingConfig;

    /**
     * Overrides the availability of a provider at runtime.
     *
     * The change is persisted in the cache and takes effect immediately on the
     * next call to all() or find(). It survives for the duration of the cache
     * TTL; a service restart with a cold cache falls back to the config default.
     */
    public function setAvailability(string $providerKey, bool $available): void;
}
