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