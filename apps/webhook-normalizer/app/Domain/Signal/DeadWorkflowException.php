<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use RuntimeException;

/**
 * Thrown when a Temporal signal cannot be delivered because the target workflow
 * is not found or has already completed. Must not be retried.
 */
final class DeadWorkflowException extends RuntimeException {}
