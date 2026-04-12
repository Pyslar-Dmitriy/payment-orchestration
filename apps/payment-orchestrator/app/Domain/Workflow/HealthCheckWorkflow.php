<?php

namespace App\Domain\Workflow;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
interface HealthCheckWorkflow
{
    #[WorkflowMethod(name: 'HealthCheck')]
    public function run(): string;
}
