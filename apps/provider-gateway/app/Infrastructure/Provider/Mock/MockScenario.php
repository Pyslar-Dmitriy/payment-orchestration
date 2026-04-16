<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Mock;

/**
 * Controllable scenarios for the MockProvider adapter.
 *
 * The active scenario is read from config('mock_provider.scenario') on every
 * adapter call, so tests can switch scenarios mid-test via config()->set().
 */
enum MockScenario: string
{
    /** Synchronous success: authorize returns captured immediately. */
    case Success = 'success';

    /** Throws ProviderTransientException on every call (simulates PSP timeout). */
    case Timeout = 'timeout';

    /** Throws ProviderHardFailureException on every call (simulates PSP decline). */
    case HardFailure = 'hard_failure';

    /** Returns isAsync=true and dispatches a single webhook immediately. */
    case AsyncWebhook = 'async_webhook';

    /** Returns isAsync=true and dispatches a webhook after mock_provider.webhook_delay_seconds. */
    case DelayedWebhook = 'delayed_webhook';

    /** Returns isAsync=true and dispatches the same webhook event twice (tests deduplication). */
    case DuplicateWebhook = 'duplicate_webhook';

    /** Returns isAsync=true and dispatches captured before authorized (tests OOO handling). */
    case OutOfOrder = 'out_of_order';
}
