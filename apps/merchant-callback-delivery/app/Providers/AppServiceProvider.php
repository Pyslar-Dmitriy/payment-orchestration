<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Http\GuzzleHttpCallbackSender;
use App\Infrastructure\Http\HttpCallbackSenderInterface;
use App\Infrastructure\RabbitMq\CallbackQueuePublisherInterface;
use App\Infrastructure\RabbitMq\CallbackRetryRouterInterface;
use App\Infrastructure\RabbitMq\RabbitMqCallbackPublisher;
use App\Infrastructure\RabbitMq\RabbitMqCallbackRetryRouter;
use App\Interfaces\Console\ConsumeCallbackWorkerCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CallbackQueuePublisherInterface::class, function (): RabbitMqCallbackPublisher {
            return new RabbitMqCallbackPublisher((array) config('rabbitmq'));
        });

        $this->app->bind(CallbackRetryRouterInterface::class, function (): RabbitMqCallbackRetryRouter {
            return new RabbitMqCallbackRetryRouter((array) config('rabbitmq'));
        });

        $this->app->bind(HttpCallbackSenderInterface::class, GuzzleHttpCallbackSender::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ConsumeCallbackWorkerCommand::class]);
        }
    }
}
