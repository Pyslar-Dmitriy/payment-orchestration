<?php

namespace App\Infrastructure\PaymentDomain;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class CircuitBreaker
{
    private const FAILURE_KEY = 'circuit_breaker:payment_domain:failures';

    private const OPEN_UNTIL_KEY = 'circuit_breaker:payment_domain:open_until';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $threshold,
        private readonly int $cooldownSeconds,
    ) {}

    public function isOpen(): bool
    {
        $openUntil = $this->cache->get(self::OPEN_UNTIL_KEY);

        if ($openUntil === null) {
            return false;
        }

        if (now()->timestamp < $openUntil) {
            return true;
        }

        // Cooldown expired — reset so the next request acts as a probe.
        $this->cache->forget(self::OPEN_UNTIL_KEY);
        $this->cache->forget(self::FAILURE_KEY);

        return false;
    }

    public function recordFailure(): void
    {
        $failures = (int) $this->cache->get(self::FAILURE_KEY, 0) + 1;

        $this->cache->put(self::FAILURE_KEY, $failures, now()->addMinutes(5));

        if ($failures >= $this->threshold) {
            $this->cache->put(
                self::OPEN_UNTIL_KEY,
                now()->addSeconds($this->cooldownSeconds)->timestamp,
                now()->addSeconds($this->cooldownSeconds + 60)
            );
        }
    }

    public function recordSuccess(): void
    {
        $this->cache->forget(self::FAILURE_KEY);
        $this->cache->forget(self::OPEN_UNTIL_KEY);
    }
}
