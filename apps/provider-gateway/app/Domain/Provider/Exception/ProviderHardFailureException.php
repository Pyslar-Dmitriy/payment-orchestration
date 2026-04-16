<?php

declare(strict_types=1);

namespace App\Domain\Provider\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown when the PSP rejects the request with a non-retryable error
 * (e.g. invalid card, insufficient funds, fraud decline).
 *
 * Callers should not retry on this exception.
 */
final class ProviderHardFailureException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $providerCode = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
