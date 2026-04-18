<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Normalizer\ProviderNormalizerRegistry;
use App\Domain\Signal\TemporalSignalDispatcherInterface;
use App\Infrastructure\Normalizer\MockProviderNormalizer;
use App\Infrastructure\Queue\RabbitMqConsumer;
use App\Infrastructure\Queue\RabbitMqConsumerContract;
use App\Infrastructure\Signal\HttpTemporalSignalDispatcher;
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

        $this->app->singleton(TemporalSignalDispatcherInterface::class, function (): HttpTemporalSignalDispatcher {
            return new HttpTemporalSignalDispatcher(
                baseUrl: (string) config('services.payment_orchestrator.base_url'),
                internalSecret: (string) config('services.payment_orchestrator.internal_secret'),
            );
        });
    }

    public function boot(): void {}
}
