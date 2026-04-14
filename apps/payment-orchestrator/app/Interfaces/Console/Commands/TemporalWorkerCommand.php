<?php

namespace App\Interfaces\Console\Commands;

use App\Domain\Workflow\HealthCheckWorkflowImpl;
use App\Domain\Workflow\PaymentWorkflowImpl;
use App\Domain\Workflow\RefundWorkflowImpl;
use Illuminate\Console\Command;
use Temporal\Worker\WorkerOptions;
use Temporal\WorkerFactory;

class TemporalWorkerCommand extends Command
{
    protected $signature = 'temporal:worker';

    protected $description = 'Start the Temporal workflow worker (run inside RoadRunner)';

    public function handle(): void
    {
        $taskQueue = config('temporal.task_queue');

        fwrite(STDERR, "Starting Temporal worker on task queue: {$taskQueue}\n");

        $factory = WorkerFactory::create();

        $worker = $factory->newWorker(
            taskQueue: $taskQueue,
            options: WorkerOptions::new(),
        );

        $worker->registerWorkflowTypes(
            HealthCheckWorkflowImpl::class,
            PaymentWorkflowImpl::class,
            RefundWorkflowImpl::class,
        );

        fwrite(STDERR, "Worker registered. Listening for tasks...\n");

        $factory->run();
    }
}
