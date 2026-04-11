<?php

namespace App\Infrastructure\Outbox\Publisher\Kafka;

use App\Infrastructure\Outbox\Publisher\BrokerPublisherInterface;

/**
 * Marker interface for the Kafka broker publisher.
 * Used as a distinct binding key so the container can distinguish
 * the Kafka publisher from the RabbitMQ publisher when injecting
 * into OutboxPublisherService.
 */
interface KafkaBrokerPublisher extends BrokerPublisherInterface {}
