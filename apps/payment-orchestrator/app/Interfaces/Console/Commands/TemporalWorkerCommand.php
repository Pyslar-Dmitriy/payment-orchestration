<?php

namespace App\Interfaces\Console\Commands;

use App\Domain\Workflow\HealthCheckWorkflowImpl;
use App\Domain\Workflow\PaymentWorkflowImpl;
use App\Domain\Workflow\RefundWorkflowImpl;
use App\Infrastructure\Activity\LedgerPostActivityImpl;
use App\Infrastructure\Activity\MerchantCallbackActivityImpl;
use App\Infrastructure\Activity\ProviderCallActivityImpl;
use App\Infrastructure\Activity\ProviderStatusQueryActivityImpl;
use App\Infrastructure\Activity\PublishDomainEventActivityImpl;
use App\Infrastructure\Activity\UpdatePaymentStatusActivityImpl;
use App\Infrastructure\Activity\UpdateRefundStatusActivityImpl;
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

        $worker->registerActivity(app(UpdatePaymentStatusActivityImpl::class));
        $worker->registerActivity(app(UpdateRefundStatusActivityImpl::class));
        $worker->registerActivity(app(ProviderCallActivityImpl::class));
        $worker->registerActivity(app(ProviderStatusQueryActivityImpl::class));
        $worker->registerActivity(app(LedgerPostActivityImpl::class));
        $worker->registerActivity(app(MerchantCallbackActivityImpl::class));
        $worker->registerActivity(app(PublishDomainEventActivityImpl::class));

        fwrite(STDERR, "Worker registered. Listening for tasks...\n");

        $factory->run();
    }
}
