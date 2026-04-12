### TASK-060 — Connect Temporal to the Laravel orchestrator service

#### What to do
Set up a dedicated service for workflow workers and connect the Temporal SDK.

#### You need to implement
- Temporal client;
- worker bootstrap;
- task queues;
- basic health checks and worker logs.

#### Done criteria
- the worker starts successfully;
- Temporal UI shows the service and workers;
- a test workflow can be started.

## Result

### Key files created / modified

| File | Change |
| --- | --- |
| `apps/payment-orchestrator/Dockerfile` | Added `ext-sockets` (needed by `spiral/roadrunner-worker`) to the `base` stage; added new `worker` target that downloads RoadRunner binary (`v2024.3.5`) and runs `rr serve` |
| `apps/payment-orchestrator/composer.json` | Added `temporal/sdk ^2.17` |
| `apps/payment-orchestrator/config/temporal.php` | New config — `address`, `namespace`, `task_queue` keys |
| `apps/payment-orchestrator/.rr.yaml` | RoadRunner config: `server.command = "php artisan temporal:worker"`, temporal plugin pointing to `${TEMPORAL_ADDRESS}` |
| `apps/payment-orchestrator/app/Infrastructure/Temporal/TemporalPinger.php` | Interface for Temporal reachability probes |
| `apps/payment-orchestrator/app/Infrastructure/Temporal/TcpTemporalPinger.php` | TCP socket implementation of `TemporalPinger` — no gRPC required in the fpm container's health path |
| `apps/payment-orchestrator/app/Infrastructure/Temporal/TemporalClientFactory.php` | `WorkflowClientInterface` factory (gRPC) — used by HTTP handlers in TASK-061+ |
| `apps/payment-orchestrator/app/Providers/TemporalServiceProvider.php` | Binds `TemporalPinger` and `WorkflowClientInterface` as lazy singletons |
| `apps/payment-orchestrator/bootstrap/providers.php` | Registered `TemporalServiceProvider` |
| `apps/payment-orchestrator/bootstrap/app.php` | Added `->withCommands([TemporalWorkerCommand::class])` and moved `/health` + `/ready` to a `then` closure (root-level, no `/api` prefix) |
| `apps/payment-orchestrator/routes/api.php` | Removed health routes (moved to bootstrap) |
| `apps/payment-orchestrator/app/Domain/Workflow/HealthCheckWorkflow.php` | Test workflow interface (`#[WorkflowInterface]`, method name `HealthCheck`) |
| `apps/payment-orchestrator/app/Domain/Workflow/HealthCheckWorkflowImpl.php` | Returns `"ok"` — validates end-to-end workflow execution |
| `apps/payment-orchestrator/app/Interfaces/Console/Commands/TemporalWorkerCommand.php` | `php artisan temporal:worker` — creates `WorkerFactory`, registers `HealthCheckWorkflowImpl`, calls `$factory->run()` |
| `apps/payment-orchestrator/app/Interfaces/Http/Controllers/HealthController.php` | `/ready` now checks both DB (`getPdo()`) and Temporal (via `TemporalPinger`) |
| `apps/payment-orchestrator/.env` + `.env.example` | Added `TEMPORAL_ADDRESS`, `TEMPORAL_NAMESPACE`, `TEMPORAL_TASK_QUEUE` |
| `infra/docker/docker-compose.yml` | Added `payment-orchestrator-worker` service (uses `worker` Dockerfile target; depends on Temporal being healthy) |
| `infra/docker/docker-compose.override.yml` | Added local dev override for worker service with source bind-mounts |

### Design decisions

- **Split fpm / worker containers**: The existing `payment-orchestrator` (php-fpm) handles HTTP health probes. A new `payment-orchestrator-worker` (RoadRunner) handles Temporal task polling. This keeps each container single-purpose and independently scalable.
- **TCP pinger for `/ready`**: The health endpoint uses a raw socket check rather than a gRPC `WorkflowClient` call so the fpm container has no compile-time gRPC dependency in its hot path. The `WorkflowClientInterface` binding is available but lazily resolved.
- **RoadRunner as the worker runtime**: `temporal/sdk` v2.17 delegates all gRPC-to-Temporal communication to RoadRunner; PHP workers communicate with RR over stdin/stdout pipes (`relay: pipes`). PHP therefore needs `ext-sockets` (for `spiral/roadrunner-worker`) but **not** `ext-grpc`. The `TemporalClientFactory` class is registered as a lazy singleton for TASK-061+ but is never resolved in this task so grpc is not called.
- **`ext-grpc` deferred**: Compiling grpc from source via PECL on Alpine is slow and fragile. It will be added to the Dockerfile in TASK-061 when `WorkflowClientInterface` is first resolved from an HTTP handler.
- **`HealthCheckWorkflow` as the test workflow**: Satisfies the "test workflow can be started" done criterion with zero dependencies on other services.