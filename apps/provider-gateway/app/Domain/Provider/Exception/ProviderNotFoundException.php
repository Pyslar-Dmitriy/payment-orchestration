<?php

declare(strict_types=1);

namespace App\Domain\Provider\Exception;

use RuntimeException;

/**
 * Thrown when no adapter is registered for the requested provider key.
 */
final class ProviderNotFoundException extends RuntimeException
{
    public function __construct(string $providerKey)
    {
        parent::__construct("No adapter registered for provider key '{$providerKey}'.");
    }
}
