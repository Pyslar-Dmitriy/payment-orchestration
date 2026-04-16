<?php

declare(strict_types=1);

namespace App\Domain\Provider\Exception;

use RuntimeException;

/**
 * Thrown when the PSP call fails due to a transient condition
 * (e.g. network timeout, 5xx from the PSP, rate limit exceeded).
 *
 * Callers should retry with backoff on this exception.
 */
final class ProviderTransientException extends RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
