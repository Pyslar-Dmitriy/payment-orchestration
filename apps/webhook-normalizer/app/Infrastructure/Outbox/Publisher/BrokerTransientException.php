<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox\Publisher;

use RuntimeException;

final class BrokerTransientException extends RuntimeException {}
