<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Provider;

use App\Domain\Provider\DTO\AuthorizeRequest;
use App\Domain\Provider\DTO\AuthorizeResponse;
use App\Domain\Provider\DTO\CaptureRequest;
use App\Domain\Provider\DTO\CaptureResponse;
use App\Domain\Provider\DTO\ParsedWebhookEvent;
use App\Domain\Provider\DTO\RefundRequest;
use App\Domain\Provider\DTO\RefundResponse;
use App\Domain\Provider\DTO\RefundStatusQueryRequest;
use App\Domain\Provider\DTO\RefundStatusQueryResponse;
use App\Domain\Provider\DTO\StatusQueryRequest;
use App\Domain\Provider\DTO\StatusQueryResponse;
use App\Domain\Provider\Exception\ProviderNotFoundException;
use App\Domain\Provider\ProviderAdapterInterface;
use App\Infrastructure\Provider\ProviderRegistry;
use Tests\TestCase;

class ProviderRegistryTest extends TestCase
{
    private ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ProviderRegistry;
    }

    public function test_get_returns_registered_adapter(): void
    {
        $adapter = $this->makeAdapter('acme');
        $this->registry->register($adapter);

        $this->assertSame($adapter, $this->registry->get('acme'));
    }

    public function test_get_throws_for_unknown_key(): void
    {
        $this->expectException(ProviderNotFoundException::class);
        $this->expectExceptionMessage("No adapter registered for provider key 'unknown'.");

        $this->registry->get('unknown');
    }

    public function test_register_overwrites_existing_adapter_for_same_key(): void
    {
        $first = $this->makeAdapter('acme');
        $second = $this->makeAdapter('acme');

        $this->registry->register($first);
        $this->registry->register($second);

        $this->assertSame($second, $this->registry->get('acme'));
    }

    public function test_multiple_adapters_are_isolated_by_key(): void
    {
        $acme = $this->makeAdapter('acme');
        $beta = $this->makeAdapter('beta');

        $this->registry->register($acme);
        $this->registry->register($beta);

        $this->assertSame($acme, $this->registry->get('acme'));
        $this->assertSame($beta, $this->registry->get('beta'));
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function makeAdapter(string $key): ProviderAdapterInterface
    {
        return new class($key) implements ProviderAdapterInterface
        {
            public function __construct(private readonly string $key) {}

            public function providerKey(): string
            {
                return $this->key;
            }

            public function authorize(AuthorizeRequest $request): AuthorizeResponse
            {
                return new AuthorizeResponse('ref-001', 'captured', false, true);
            }

            public function capture(CaptureRequest $request): CaptureResponse
            {
                return new CaptureResponse('ref-001', 'captured', false);
            }

            public function refund(RefundRequest $request): RefundResponse
            {
                return new RefundResponse('ref-002', 'refunded', false);
            }

            public function queryPaymentStatus(StatusQueryRequest $request): StatusQueryResponse
            {
                return new StatusQueryResponse('captured', true, false, false);
            }

            public function queryRefundStatus(RefundStatusQueryRequest $request): RefundStatusQueryResponse
            {
                return new RefundStatusQueryResponse('refunded', true, false);
            }

            public function parseWebhook(array $payload, array $headers): ParsedWebhookEvent
            {
                return new ParsedWebhookEvent('evt-1', 'ref-001', 'payment.captured', 'captured', 'CAPTURED', $payload);
            }

            public function mapStatus(string $rawStatus): string
            {
                return 'captured';
            }
        };
    }
}
