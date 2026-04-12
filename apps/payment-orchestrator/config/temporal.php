<?php

return [
    /*
     * gRPC address of the Temporal frontend service.
     * In Docker Compose the container name resolves as a hostname.
     */
    'address' => env('TEMPORAL_ADDRESS', 'temporal:7233'),

    /*
     * Temporal namespace.  All workflows and activities run inside this namespace.
     */
    'namespace' => env('TEMPORAL_NAMESPACE', 'default'),

    /*
     * Task queue this worker polls.  Must match the queue used when starting
     * workflows from the HTTP layer.
     */
    'task_queue' => env('TEMPORAL_TASK_QUEUE', 'payment-orchestrator'),
];
