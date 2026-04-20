<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use RuntimeException;

/**
 * Thrown when a Temporal signal cannot be delivered because the target workflow
 * is not found or has already completed. Must not be retried.
 */
final class DeadWorkflowException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $deadReason = 'workflow_not_found',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getDeadReason(): string
    {
        return $this->deadReason;
    }
}
