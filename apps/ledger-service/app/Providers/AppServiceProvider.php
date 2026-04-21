<?php

namespace App\Providers;

use App\Infrastructure\Outbox\Publisher\BrokerPublisherInterface;
use App\Infrastructure\Outbox\Publisher\Kafka\KafkaPublisher;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            BrokerPublisherInterface::class,
            fn () => new KafkaPublisher(config('outbox.kafka')),
        );
    }

    public function boot(): void {}
}
