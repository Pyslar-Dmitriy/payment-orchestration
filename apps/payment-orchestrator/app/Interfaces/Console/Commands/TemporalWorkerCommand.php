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

        $worker->registerActivity(UpdatePaymentStatusActivityImpl::class, fn () => app(UpdatePaymentStatusActivityImpl::class));
        $worker->registerActivity(UpdateRefundStatusActivityImpl::class, fn () => app(UpdateRefundStatusActivityImpl::class));
        $worker->registerActivity(ProviderCallActivityImpl::class);
        $worker->registerActivity(ProviderStatusQueryActivityImpl::class);
        $worker->registerActivity(LedgerPostActivityImpl::class);
        $worker->registerActivity(MerchantCallbackActivityImpl::class);
        $worker->registerActivity(PublishDomainEventActivityImpl::class);

        fwrite(STDERR, "Worker registered. Listening for tasks...\n");

        $factory->run();
    }
}
