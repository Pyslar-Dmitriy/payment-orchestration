<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Normalizer\ProviderNormalizerRegistry;
use App\Domain\Signal\TemporalSignalDispatcherInterface;
use App\Infrastructure\Normalizer\MockProviderNormalizer;
use App\Infrastructure\Outbox\KafkaEnvelopeBuilder;
use App\Infrastructure\Outbox\OutboxPublisherService;
use App\Infrastructure\Outbox\Publisher\BrokerPublisherInterface;
use App\Infrastructure\Outbox\Publisher\FakeBroker\FakeBrokerPublisher;
use App\Infrastructure\Outbox\Publisher\KafkaPublisher;
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

        $this->app->singleton(BrokerPublisherInterface::class, function (): BrokerPublisherInterface {
            if (app()->environment('testing')) {
                return new FakeBrokerPublisher;
            }

            return new KafkaPublisher(config('outbox.kafka'));
        });

        $this->app->singleton(OutboxPublisherService::class, function (): OutboxPublisherService {
            return new OutboxPublisherService(
                kafkaPublisher: $this->app->make(BrokerPublisherInterface::class),
                envelopeBuilder: new KafkaEnvelopeBuilder,
            );
        });
    }

    public function boot(): void {}
}
