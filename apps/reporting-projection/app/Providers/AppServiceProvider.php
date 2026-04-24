<?php

namespace App\Providers;

use App\Interfaces\Console\ConsumeKafkaEventsCommand;
use App\Interfaces\Console\ResetProjectionsCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->commands([
            ConsumeKafkaEventsCommand::class,
            ResetProjectionsCommand::class,
        ]);
    }
}
