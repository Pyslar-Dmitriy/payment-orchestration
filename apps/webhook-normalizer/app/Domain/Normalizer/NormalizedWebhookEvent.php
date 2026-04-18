<?php

declare(strict_types=1);

namespace App\Domain\Normalizer;

final class NormalizedWebhookEvent
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $paymentId,
        public readonly string $providerEventId,
        public readonly string $providerReference,
        public readonly string $eventType,
        public readonly string $internalStatus,
        public readonly string $rawStatus,
        public readonly array $rawPayload,
    ) {}
}
