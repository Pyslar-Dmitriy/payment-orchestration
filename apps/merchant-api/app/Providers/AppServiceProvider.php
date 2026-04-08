<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

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
