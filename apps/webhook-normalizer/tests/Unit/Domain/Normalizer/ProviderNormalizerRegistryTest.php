<?php

namespace Tests\Unit\Domain\Normalizer;

use App\Domain\Normalizer\NormalizedWebhookEvent;
use App\Domain\Normalizer\ProviderNormalizerInterface;
use App\Domain\Normalizer\ProviderNormalizerRegistry;
use App\Domain\Normalizer\UnmappableWebhookException;
use App\Infrastructure\Normalizer\MockProviderNormalizer;
use PHPUnit\Framework\TestCase;

class ProviderNormalizerRegistryTest extends TestCase
{
    private const PAYMENT_UUID = '00000000-0000-0000-0000-000000000001';

    public function test_dispatches_to_registered_normalizer(): void
    {
        $registry = new ProviderNormalizerRegistry([new MockProviderNormalizer]);

        $event = $registry->normalize('mock', [
            'event_id' => 'mock-evt-001',
            'payment_reference' => 'mock-'.self::PAYMENT_UUID,
            'event_type' => 'payment.captured',
            'status' => 'CAPTURED',
        ]);

        $this->assertSame('captured', $event->internalStatus);
        $this->assertSame('mock', $event->provider);
    }

    public function test_throws_for_unknown_provider(): void
    {
        $registry = new ProviderNormalizerRegistry([new MockProviderNormalizer]);

        $this->expectException(UnmappableWebhookException::class);
        $this->expectExceptionMessage('stripe');

        $registry->normalize('stripe', ['status' => 'succeeded']);
    }

    public function test_throws_for_empty_registry(): void
    {
        $registry = new ProviderNormalizerRegistry([]);

        $this->expectException(UnmappableWebhookException::class);

        $registry->normalize('mock', []);
    }

    public function test_multiple_normalizers_dispatch_correctly(): void
    {
        $stubNormalizer = new class implements ProviderNormalizerInterface
        {
            public function provider(): string
            {
                return 'stub';
            }

            public function normalize(array $rawPayload): NormalizedWebhookEvent
            {
                return new NormalizedWebhookEvent(
                    provider: 'stub',
                    paymentId: '00000000-0000-0000-0000-000000000099',
                    providerEventId: 'stub-evt-1',
                    providerReference: 'stub-ref-1',
                    eventType: 'payment.settled',
                    internalStatus: 'captured',
                    rawStatus: 'settled',
                    rawPayload: $rawPayload,
                );
            }
        };

        $registry = new ProviderNormalizerRegistry([
            new MockProviderNormalizer,
            $stubNormalizer,
        ]);

        $mockEvent = $registry->normalize('mock', [
            'event_id' => 'mock-evt-001',
            'payment_reference' => 'mock-'.self::PAYMENT_UUID,
            'event_type' => 'payment.captured',
            'status' => 'AUTHORIZED',
        ]);
        $this->assertSame('authorized', $mockEvent->internalStatus);

        $stubEvent = $registry->normalize('stub', ['anything' => 'here']);
        $this->assertSame('captured', $stubEvent->internalStatus);
        $this->assertSame('stub', $stubEvent->provider);
    }
}
