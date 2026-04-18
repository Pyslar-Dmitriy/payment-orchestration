<?php

declare(strict_types=1);

namespace App\Domain\Normalizer;

final class ProviderNormalizerRegistry
{
    /** @var array<string, ProviderNormalizerInterface> */
    private array $normalizers = [];

    /**
     * @param  ProviderNormalizerInterface[]  $normalizers
     */
    public function __construct(array $normalizers = [])
    {
        foreach ($normalizers as $normalizer) {
            $this->normalizers[$normalizer->provider()] = $normalizer;
        }
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     *
     * @throws UnmappableWebhookException
     */
    public function normalize(string $provider, array $rawPayload): NormalizedWebhookEvent
    {
        if (! isset($this->normalizers[$provider])) {
            throw new UnmappableWebhookException("No normalizer registered for provider: {$provider}");
        }

        return $this->normalizers[$provider]->normalize($rawPayload);
    }
}
