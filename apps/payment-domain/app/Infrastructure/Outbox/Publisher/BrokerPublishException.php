<?php

namespace App\Infrastructure\Outbox\Publisher;

use RuntimeException;

/**
 * Thrown when publishing fails due to a permanent, non-retriable condition
 * such as an authentication error or schema validation rejection.
 */
final class BrokerPublishException extends RuntimeException {}
