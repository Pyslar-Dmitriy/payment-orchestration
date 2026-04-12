<?php

namespace App\Domain\Workflow;

class HealthCheckWorkflowImpl implements HealthCheckWorkflow
{
    public function run(): string
    {
        return 'ok';
    }
}
