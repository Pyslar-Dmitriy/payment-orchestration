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

        if (! isset(self::STATUS_MAP[$rawStatus])) {
            throw new UnmappableWebhookException("Unknown mock provider status: {$rawStatus}");
        }

        return new NormalizedWebhookEvent(
            provider: 'mock',
            providerEventId: $providerEventId,
            providerReference: $providerReference,
            eventType: $eventType,
            internalStatus: self::STATUS_MAP[$rawStatus],
            rawStatus: $rawStatus,
            rawPayload: $rawPayload,
        );
    }
}
