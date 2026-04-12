<?php

namespace App\Providers;

use App\Infrastructure\Temporal\TcpTemporalPinger;
use App\Infrastructure\Temporal\TemporalClientFactory;
use App\Infrastructure\Temporal\TemporalPinger;
use Illuminate\Support\ServiceProvider;
use Temporal\Client\WorkflowClientInterface;

class TemporalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TemporalPinger::class, function (): TemporalPinger {
            return new TcpTemporalPinger(config('temporal.address'));
        });

        // WorkflowClientInterface is bound lazily; it is only created when the
        // HTTP layer needs to start or signal a workflow (TASK-061+).
        $this->app->singleton(WorkflowClientInterface::class, function (): WorkflowClientInterface {
            return TemporalClientFactory::create(
                address: config('temporal.address'),
                namespace: config('temporal.namespace'),
            );
        });
    }
}
