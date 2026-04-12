<?php

namespace Tests\Feature;

use App\Infrastructure\Temporal\TemporalPinger;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    // ── /health ───────────────────────────────────────────────────────────────

    public function test_health_returns_ok(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk()->assertJson(['status' => 'ok']);
    }

    // ── /ready ────────────────────────────────────────────────────────────────

    public function test_ready_returns_ok_when_all_dependencies_are_healthy(): void
    {
        $this->app->instance(TemporalPinger::class, $this->reachablePinger());

        $response = $this->getJson('/ready');

        $response->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_ready_returns_503_when_database_is_unavailable(): void
    {
        // Force the DB connection to a bad host so getPdo() throws.
        config(['database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');

        $this->app->instance(TemporalPinger::class, $this->reachablePinger());

        $response = $this->getJson('/ready');

        $response->assertStatus(503)->assertJson(['status' => 'error', 'message' => 'Database unavailable.']);
    }

    public function test_ready_returns_503_when_temporal_is_unreachable(): void
    {
        $this->app->instance(TemporalPinger::class, $this->unreachablePinger());

        $response = $this->getJson('/ready');

        $response->assertStatus(503)->assertJson(['status' => 'error', 'message' => 'Temporal unavailable.']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function reachablePinger(): TemporalPinger
    {
        return new class implements TemporalPinger
        {
            public function isReachable(): bool
            {
                return true;
            }
        };
    }

    private function unreachablePinger(): TemporalPinger
    {
        return new class implements TemporalPinger
        {
            public function isReachable(): bool
            {
                return false;
            }
        };
    }
}
