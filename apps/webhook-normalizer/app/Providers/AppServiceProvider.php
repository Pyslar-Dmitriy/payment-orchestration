<?php

namespace App\Providers;

use App\Infrastructure\Queue\RabbitMqConsumer;
use App\Infrastructure\Queue\RabbitMqConsumerContract;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RabbitMqConsumerContract::class, function (): RabbitMqConsumer {
            return new RabbitMqConsumer(config('rabbitmq'));
        });
    }

    public function boot(): void {}
}
