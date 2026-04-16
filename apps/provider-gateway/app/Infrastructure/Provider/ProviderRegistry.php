<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider;

use App\Domain\Provider\Exception\ProviderNotFoundException;
use App\Domain\Provider\ProviderAdapterInterface;
use App\Domain\Provider\ProviderRegistryInterface;

/**
 * In-process registry that maps provider keys to adapter instances.
 *
 * Adapters are registered at boot time via AppServiceProvider.
 * Adding a new PSP requires only: implementing ProviderAdapterInterface
 * and calling $registry->register(new MyPspAdapter(...)).
 */
final class ProviderRegistry implements ProviderRegistryInterface
{
    /** @var array<string, ProviderAdapterInterface> */
    private array $adapters = [];

    public function get(string $providerKey): ProviderAdapterInterface
    {
        if (! isset($this->adapters[$providerKey])) {
            throw new ProviderNotFoundException($providerKey);
        }

        return $this->adapters[$providerKey];
    }

    public function register(ProviderAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->providerKey()] = $adapter;
    }
}
