<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\RabbitMq\CallbackQueuePublisherInterface;
use App\Infrastructure\RabbitMq\RabbitMqCallbackPublisher;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CallbackQueuePublisherInterface::class, function (): RabbitMqCallbackPublisher {
            return new RabbitMqCallbackPublisher((array) config('rabbitmq'));
        });
    }

    public function boot(): void {}
}
