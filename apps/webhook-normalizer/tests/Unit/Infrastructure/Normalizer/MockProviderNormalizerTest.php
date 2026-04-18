<?php

namespace Tests\Unit\Infrastructure\Normalizer;

use App\Domain\Normalizer\UnmappableWebhookException;
use App\Infrastructure\Normalizer\MockProviderNormalizer;
use PHPUnit\Framework\TestCase;

class MockProviderNormalizerTest extends TestCase
{
    private MockProviderNormalizer $normalizer;

    private const PAYMENT_UUID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        $this->normalizer = new MockProviderNormalizer;
    }

    public function test_provider_key_is_mock(): void
    {
        $this->assertSame('mock', $this->normalizer->provider());
    }

    // -----------------------------------------------------------------------
    // Happy path — all supported statuses
    // -----------------------------------------------------------------------

    public function test_maps_captured_to_captured(): void
    {
        $event = $this->normalizer->normalize($this->payload('CAPTURED'));

        $this->assertSame('captured', $event->internalStatus);
        $this->assertSame('CAPTURED', $event->rawStatus);
    }

    public function test_maps_authorized_to_authorized(): void
    {
        $event = $this->normalizer->normalize($this->payload('AUTHORIZED'));

        $this->assertSame('authorized', $event->internalStatus);
        $this->assertSame('AUTHORIZED', $event->rawStatus);
    }

    public function test_maps_failed_to_failed(): void
    {
        $event = $this->normalizer->normalize($this->payload('FAILED'));

        $this->assertSame('failed', $event->internalStatus);
        $this->assertSame('FAILED', $event->rawStatus);
    }

    public function test_maps_refunded_to_refunded(): void
    {
        $event = $this->normalizer->normalize($this->payload('REFUNDED'));

        $this->assertSame('refunded', $event->internalStatus);
        $this->assertSame('REFUNDED', $event->rawStatus);
    }

    public function test_maps_pending_to_pending(): void
    {
        $event = $this->normalizer->normalize($this->payload('PENDING'));

        $this->assertSame('pending', $event->internalStatus);
        $this->assertSame('PENDING', $event->rawStatus);
    }

    // -----------------------------------------------------------------------
    // Case-insensitive status input
    // -----------------------------------------------------------------------

    public function test_maps_lowercase_captured(): void
    {
        $event = $this->normalizer->normalize($this->payload('captured'));

        $this->assertSame('captured', $event->internalStatus);
    }

    public function test_maps_mixed_case_status(): void
    {
        $event = $this->normalizer->normalize($this->payload('Authorized'));

        $this->assertSame('authorized', $event->internalStatus);
    }

    // -----------------------------------------------------------------------
    // DTO field propagation
    // -----------------------------------------------------------------------

    public function test_propagates_provider_event_id(): void
    {
        $event = $this->normalizer->normalize($this->payload('CAPTURED', eventId: 'mock-evt-abc'));

        $this->assertSame('mock-evt-abc', $event->providerEventId);
    }

    public function test_propagates_provider_reference(): void
    {
        $uuid = '00000000-0000-0000-0000-000000000099';
        $event = $this->normalizer->normalize($this->payload('CAPTURED', paymentUuid: $uuid));

        $this->assertSame('mock-'.$uuid, $event->providerReference);
    }

    public function test_propagates_payment_id(): void
    {
        $uuid = '00000000-0000-0000-0000-000000000099';
        $event = $this->normalizer->normalize($this->payload('CAPTURED', paymentUuid: $uuid));

        $this->assertSame($uuid, $event->paymentId);
    }

    public function test_propagates_event_type(): void
    {
        $event = $this->normalizer->normalize($this->payload('CAPTURED', eventType: 'payment.captured'));

        $this->assertSame('payment.captured', $event->eventType);
    }

    public function test_propagates_provider_name(): void
    {
        $event = $this->normalizer->normalize($this->payload('CAPTURED'));

        $this->assertSame('mock', $event->provider);
    }

    public function test_preserves_raw_payload(): void
    {
        $payload = $this->payload('CAPTURED');
        $event = $this->normalizer->normalize($payload);

        $this->assertSame($payload, $event->rawPayload);
    }

    public function test_defaults_event_type_when_missing(): void
    {
        $payload = $this->payload('CAPTURED');
        unset($payload['event_type']);

        $event = $this->normalizer->normalize($payload);

        $this->assertSame('payment.unknown', $event->eventType);
    }

    // -----------------------------------------------------------------------
    // Error cases
    // -----------------------------------------------------------------------

    public function test_throws_on_unknown_status(): void
    {
        $this->expectException(UnmappableWebhookException::class);
        $this->expectExceptionMessage('DECLINED');

        $this->normalizer->normalize($this->payload('DECLINED'));
    }

    public function test_throws_on_missing_event_id(): void
    {
        $this->expectException(UnmappableWebhookException::class);
        $this->expectExceptionMessage('event_id');

        $payload = $this->payload('CAPTURED');
        unset($payload['event_id']);

        $this->normalizer->normalize($payload);
    }

    public function test_throws_on_missing_payment_reference(): void
    {
        $this->expectException(UnmappableWebhookException::class);
        $this->expectExceptionMessage('payment_reference');

        $payload = $this->payload('CAPTURED');
        unset($payload['payment_reference']);

        $this->normalizer->normalize($payload);
    }

    public function test_throws_on_empty_status(): void
    {
        $this->expectException(UnmappableWebhookException::class);

        $this->normalizer->normalize($this->payload(''));
    }

    public function test_throws_when_payment_reference_missing_mock_prefix(): void
    {
        $this->expectException(UnmappableWebhookException::class);
        $this->expectExceptionMessage('mock-');

        $payload = $this->payload('CAPTURED');
        $payload['payment_reference'] = self::PAYMENT_UUID;

        $this->normalizer->normalize($payload);
    }

    public function test_throws_when_payment_reference_suffix_is_not_a_uuid(): void
    {
        $this->expectException(UnmappableWebhookException::class);
        $this->expectExceptionMessage('UUID');

        $payload = $this->payload('CAPTURED');
        $payload['payment_reference'] = 'mock-not-a-uuid';

        $this->normalizer->normalize($payload);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function payload(
        string $status,
        string $eventId = 'mock-evt-001',
        string $paymentUuid = self::PAYMENT_UUID,
        string $eventType = 'payment.captured',
    ): array {
        return [
            'event_id' => $eventId,
            'payment_reference' => 'mock-'.$paymentUuid,
            'event_type' => $eventType,
            'status' => $status,
        ];
    }
}
