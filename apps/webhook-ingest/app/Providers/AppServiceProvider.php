<?php

namespace App\Providers;

use App\Infrastructure\Queue\RabbitMqPublisher;
use App\Infrastructure\Queue\RabbitMqPublisherContract;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            RabbitMqPublisherContract::class,
            fn () => new RabbitMqPublisher(config('rabbitmq')),
        );
    }

    public function boot(): void {}
}
