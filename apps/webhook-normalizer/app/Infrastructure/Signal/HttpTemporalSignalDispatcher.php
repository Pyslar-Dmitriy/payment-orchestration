<?php

declare(strict_types=1);

namespace App\Infrastructure\Signal;

use App\Domain\Normalizer\NormalizedWebhookEvent;
use App\Domain\Signal\DeadWorkflowException;
use App\Domain\Signal\TemporalSignalDispatcherInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HttpTemporalSignalDispatcher implements TemporalSignalDispatcherInterface
{
    private const EVENT_TYPE_TO_SIGNAL = [
        'payment.authorized' => 'provider.authorization_result',
        'payment.captured' => 'provider.capture_result',
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $internalSecret,
    ) {}

    public function dispatch(NormalizedWebhookEvent $event, string $correlationId): void
    {
        $signalName = self::EVENT_TYPE_TO_SIGNAL[$event->eventType] ?? null;

        if ($signalName === null) {
            return;
        }

        $url = rtrim($this->baseUrl, '/').'/api/signals/payments/'.$event->paymentId;

        try {
            $response = Http::withHeaders([
                'X-Internal-Secret' => $this->internalSecret,
                'Accept' => 'application/json',
            ])->post($url, [
                'signal_name' => $signalName,
                'provider_event_id' => $event->providerEventId,
                'provider_status' => $event->internalStatus,
                'provider_reference' => $event->providerReference,
                'correlation_id' => $correlationId,
            ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException(
                'Transient error signalling Temporal workflow: '.$e->getMessage(),
                previous: $e,
            );
        }

        if ($response->status() === 404) {
            $reason = (string) ($response->json('reason') ?? 'workflow_not_found');

            throw new DeadWorkflowException(
                "Temporal workflow not found for payment {$event->paymentId}",
                $reason,
            );
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Unexpected HTTP {$response->status()} from payment-orchestrator signal endpoint",
            );
        }
    }
}
