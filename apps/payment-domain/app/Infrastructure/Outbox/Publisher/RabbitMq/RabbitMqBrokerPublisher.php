<?php

namespace App\Infrastructure\Outbox\Publisher\RabbitMq;

use App\Infrastructure\Outbox\Publisher\BrokerPublisherInterface;

/**
 * Marker interface for the RabbitMQ broker publisher.
 * Used as a distinct binding key so the container can distinguish
 * the RabbitMQ publisher from the Kafka publisher when injecting
 * into OutboxPublisherService.
 */
interface RabbitMqBrokerPublisher extends BrokerPublisherInterface {}
