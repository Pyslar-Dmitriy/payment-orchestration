<?php

namespace App\Providers;

use App\Infrastructure\PaymentDomain\CircuitBreaker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CircuitBreaker::class, function ($app): CircuitBreaker {
            return new CircuitBreaker(
                cache: $app->make(CacheRepository::class),
                threshold: (int) config('services.payment_domain.circuit_breaker.threshold', 5),
                cooldownSeconds: (int) config('services.payment_domain.circuit_breaker.cooldown_seconds', 60),
            );
        });
    }

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $token = $request->bearerToken();
            $key = $token ? sha1($token) : $request->ip();

            return Limit::perMinute((int) config('services.rate_limit.per_minute', 60))
                ->by($key);
        });
    }
}
