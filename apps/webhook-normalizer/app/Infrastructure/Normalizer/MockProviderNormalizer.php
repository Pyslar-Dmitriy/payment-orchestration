<?php

declare(strict_types=1);

namespace App\Infrastructure\Normalizer;

use App\Domain\Normalizer\NormalizedWebhookEvent;
use App\Domain\Normalizer\ProviderNormalizerInterface;
use App\Domain\Normalizer\UnmappableWebhookException;

final class MockProviderNormalizer implements ProviderNormalizerInterface
{
    private const STATUS_MAP = [
        'AUTHORIZED' => 'authorized',
        'CAPTURED' => 'captured',
        'FAILED' => 'failed',
        'REFUNDED' => 'refunded',
        'PENDING' => 'pending',
    ];

    public function provider(): string
    {
        return 'mock';
    }

    public function normalize(array $rawPayload): NormalizedWebhookEvent
    {
        $providerEventId = (string) ($rawPayload['event_id'] ?? '');
        $providerReference = (string) ($rawPayload['payment_reference'] ?? '');
        $eventType = (string) ($rawPayload['event_type'] ?? 'payment.unknown');
        $rawStatus = strtoupper((string) ($rawPayload['status'] ?? ''));

        if ($providerEventId === '') {
            throw new UnmappableWebhookException('Mock provider webhook is missing event_id');
        }

        if ($providerReference === '') {
            throw new UnmappableWebhookException('Mock provider webhook is missing payment_reference');
        }

        if (! str_starts_with($providerReference, 'mock-')) {
            throw new UnmappableWebhookException('Mock provider payment_reference must start with mock-');
        }

        $paymentId = substr($providerReference, 5);

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $paymentId)) {
            throw new UnmappableWebhookException('Mock provider payment_reference does not contain a valid UUID after mock- prefix');
        }

        if (! isset(self::STATUS_MAP[$rawStatus])) {
            throw new UnmappableWebhookException("Unknown mock provider status: {$rawStatus}");
        }

        return new NormalizedWebhookEvent(
            provider: 'mock',
            paymentId: $paymentId,
            providerEventId: $providerEventId,
            providerReference: $providerReference,
            eventType: $eventType,
            internalStatus: self::STATUS_MAP[$rawStatus],
            rawStatus: $rawStatus,
            rawPayload: $rawPayload,
        );
    }
}
