<?php

namespace App\Providers;

use App\Domain\Provider\ProviderAdapterInterface;
use App\Domain\Provider\ProviderRegistryInterface;
use App\Infrastructure\Provider\Audit\AuditingProviderAdapter;
use App\Infrastructure\Provider\Audit\ProviderAuditLogger;
use App\Infrastructure\Provider\Audit\ProviderAuditLoggerInterface;
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
        $this->app->singleton(ProviderAuditLoggerInterface::class, ProviderAuditLogger::class);
    }

    /**
     * Bootstrap any application services.
     *
     * PSP adapters are registered here. To add a new provider, implement
     * ProviderAdapterInterface and call $registry->register(new MyAdapter(...)).
     * Each adapter is automatically wrapped with AuditingProviderAdapter so all
     * provider calls are persisted to the provider_audit_logs table.
     */
    public function boot(): void
    {
        /** @var ProviderRegistryInterface $registry */
        $registry = $this->app->make(ProviderRegistryInterface::class);

        $registry->register($this->audited(new MockProviderAdapter));
    }

    private function audited(ProviderAdapterInterface $adapter): ProviderAdapterInterface
    {
        return new AuditingProviderAdapter($adapter, $this->app->make(ProviderAuditLoggerInterface::class));
    }
}
