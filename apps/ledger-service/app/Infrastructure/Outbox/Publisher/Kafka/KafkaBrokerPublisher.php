<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox\Publisher\Kafka;

use App\Infrastructure\Outbox\Publisher\BrokerPublisherInterface;

interface KafkaBrokerPublisher extends BrokerPublisherInterface {}
