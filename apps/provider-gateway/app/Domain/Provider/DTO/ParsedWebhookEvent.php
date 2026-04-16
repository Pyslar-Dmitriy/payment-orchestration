<?php

declare(strict_types=1);

namespace App\Domain\Provider\DTO;

final class ParsedWebhookEvent
{
    /**
     * @param  string  $providerEventId  Unique event ID assigned by the PSP. Used for deduplication.
     * @param  string  $providerReference  PSP transaction reference this event relates to.
     * @param  string  $eventType  PSP-specific event type string (e.g. 'payment.captured').
     * @param  string  $normalizedStatus  Internal status mapped from rawStatus (e.g. 'captured').
     * @param  string  $rawStatus  Original status string from the PSP payload.
     * @param  array<string, mixed>  $rawPayload  Full raw payload for storage and debugging.
     */
    public function __construct(
        public readonly string $providerEventId,
        public readonly string $providerReference,
        public readonly string $eventType,
        public readonly string $normalizedStatus,
        public readonly string $rawStatus,
        public readonly array $rawPayload,
    ) {}
}
