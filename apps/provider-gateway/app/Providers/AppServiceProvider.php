<?php

namespace App\Providers;

use App\Domain\Provider\ProviderRegistryInterface;
use App\Infrastructure\Provider\Mock\MockProviderAdapter;
use App\Infrastructure\Provider\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProviderRegistryInterface::class, ProviderRegistry::class);
    }

    /**
     * Bootstrap any application services.
     *
     * PSP adapters are registered here. To add a new provider, implement
     * ProviderAdapterInterface and call $registry->register(new MyAdapter(...)).
     */
    public function boot(): void
    {
        /** @var ProviderRegistryInterface $registry */
        $registry = $this->app->make(ProviderRegistryInterface::class);

        $registry->register(new MockProviderAdapter);
    }
}
