<?php

declare(strict_types=1);

namespace App\Domain\Provider;

use App\Domain\Provider\Exception\ProviderNotFoundException;

/**
 * Resolves a registered ProviderAdapterInterface by its provider key.
 *
 * Keeps the application layer decoupled from adapter instantiation.
 */
interface ProviderRegistryInterface
{
    /**
     * Returns the adapter registered for the given provider key.
     *
     * @throws ProviderNotFoundException when no adapter is registered for the key.
     */
    public function get(string $providerKey): ProviderAdapterInterface;

    /**
     * Registers an adapter. Replaces any previously registered adapter for the same key.
     */
    public function register(ProviderAdapterInterface $adapter): void;
}
