<?php

namespace App\Providers;

use App\Infrastructure\Outbox\Publisher\Kafka\KafkaBrokerPublisher;
use App\Infrastructure\Outbox\Publisher\Kafka\KafkaPublisher;
use App\Infrastructure\Outbox\Publisher\RabbitMq\RabbitMqBrokerPublisher;
use App\Infrastructure\Outbox\Publisher\RabbitMq\RabbitMqPublisher;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            KafkaBrokerPublisher::class,
            fn () => new KafkaPublisher(config('outbox.kafka')),
        );

        $this->app->bind(
            RabbitMqBrokerPublisher::class,
            fn () => new RabbitMqPublisher(config('outbox.rabbitmq')),
        );
    }

    public function boot(): void {}
}
