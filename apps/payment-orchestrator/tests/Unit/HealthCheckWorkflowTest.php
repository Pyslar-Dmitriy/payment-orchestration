<?php

namespace Tests\Unit;

use App\Domain\Workflow\HealthCheckWorkflowImpl;
use PHPUnit\Framework\TestCase;

class HealthCheckWorkflowTest extends TestCase
{
    public function test_run_returns_ok(): void
    {
        $workflow = new HealthCheckWorkflowImpl;

        $this->assertSame('ok', $workflow->run());
    }
}
