<?php

namespace App\Infrastructure\Outbox\Publisher;

use RuntimeException;

/**
 * Thrown when publishing fails due to a transient, retriable condition
 * such as a connection reset or broker timeout.
 */
final class BrokerTransientException extends RuntimeException {}
