<?php

declare(strict_types=1);

namespace App\Domain\Normalizer;

interface ProviderNormalizerInterface
{
    public function provider(): string;

    /**
     * @param  array<string, mixed>  $rawPayload
     *
     * @throws UnmappableWebhookException
     */
    public function normalize(array $rawPayload): NormalizedWebhookEvent;
}
