<?php

namespace App\Providers;

use App\Domain\Normalizer\ProviderNormalizerRegistry;
use App\Infrastructure\Normalizer\MockProviderNormalizer;
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

        $this->app->singleton(ProviderNormalizerRegistry::class, function (): ProviderNormalizerRegistry {
            return new ProviderNormalizerRegistry([
                new MockProviderNormalizer,
            ]);
        });
    }

    public function boot(): void {}
}
