<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Normalizer\NormalizedWebhookEvent;

interface TemporalSignalDispatcherInterface
{
    /**
     * Dispatch a normalized webhook event as a Temporal workflow signal.
     *
     * If the event type does not map to a known signal, the call is a no-op.
     *
     * @throws DeadWorkflowException when the workflow is not found or already closed — must not be retried
     * @throws \RuntimeException on transient failure (network, service unavailable) — safe to retry
     */
    public function dispatch(NormalizedWebhookEvent $event, string $correlationId): void;
}
