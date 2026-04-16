<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

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
use App\Domain\Provider\Exception\ProviderTransientException;
use App\Domain\Provider\ProviderAdapterInterface;
use App\Domain\Provider\ProviderRegistryInterface;
use Tests\Feature\Http\Concerns\RegistersFakeAdapter;
use Tests\TestCase;

class ProviderPaymentStatusTest extends TestCase
{
    use RegistersFakeAdapter;

    private string $paymentUuid = '550e8400-e29b-41d4-a716-446655440000';

    private string $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_returns_captured_status(): void
    {
        $this->registerFakeAdapter(
            statusQueryResponse: new StatusQueryResponse('captured', true, false, false),
        );

        $response = $this->getJson(
            "/api/v1/provider/payments/{$this->paymentUuid}/status?provider_key=fake&correlation_id={$this->correlationId}",
        );

        $response->assertStatus(200)
            ->assertExactJson([
                'provider_status' => 'captured',
                'is_captured' => true,
                'is_authorized' => false,
                'is_failed' => false,
            ]);
    }

    public function test_returns_failed_status(): void
    {
        $this->registerFakeAdapter(
            statusQueryResponse: new StatusQueryResponse('failed', false, false, true),
        );

        $response = $this->getJson(
            "/api/v1/provider/payments/{$this->paymentUuid}/status?provider_key=fake&correlation_id={$this->correlationId}",
        );

        $response->assertStatus(200)
            ->assertExactJson([
                'provider_status' => 'failed',
                'is_captured' => false,
                'is_authorized' => false,
                'is_failed' => true,
            ]);
    }

    public function test_accepts_optional_provider_reference_query_param(): void
    {
        $this->registerFakeAdapter();

        $response = $this->getJson(
            "/api/v1/provider/payments/{$this->paymentUuid}/status"
            ."?provider_key=fake&correlation_id={$this->correlationId}&provider_reference=psp-txn-abc",
        );

        $response->assertStatus(200);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_returns_422_when_provider_key_is_missing(): void
    {
        $response = $this->getJson(
            "/api/v1/provider/payments/{$this->paymentUuid}/status?correlation_id={$this->correlationId}",
        );

        $response->assertStatus(422);
    }

    public function test_returns_422_when_correlation_id_is_missing(): void
    {
        $response = $this->getJson(
            "/api/v1/provider/payments/{$this->paymentUuid}/status?provider_key=fake",
        );

        $response->assertStatus(422);
    }

    // ── Error paths ───────────────────────────────────────────────────────────

    public function test_returns_422_when_provider_key_is_not_registered(): void
    {
        $response = $this->getJson(
            "/api/v1/provider/payments/{$this->paymentUuid}/status?provider_key=nonexistent&correlation_id={$this->correlationId}",
        );

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => "No adapter registered for provider key 'nonexistent'."]);
    }

    public function test_returns_503_when_adapter_throws_transient_failure(): void
    {
        $this->registerAdapterThatThrows(new ProviderTransientException('PSP timeout'));

        $response = $this->getJson(
            "/api/v1/provider/payments/{$this->paymentUuid}/status?provider_key=fake&correlation_id={$this->correlationId}",
        );

        $response->assertStatus(503);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function registerAdapterThatThrows(\Throwable $exception): void
    {
        $adapter = new class($exception) implements ProviderAdapterInterface
        {
            public function __construct(private readonly \Throwable $exception) {}

            public function providerKey(): string
            {
                return 'fake';
            }

            public function authorize(AuthorizeRequest $r): AuthorizeResponse
            {
                throw $this->exception;
            }

            public function capture(CaptureRequest $r): CaptureResponse
            {
                throw $this->exception;
            }

            public function refund(RefundRequest $r): RefundResponse
            {
                throw $this->exception;
            }

            public function queryPaymentStatus(StatusQueryRequest $r): StatusQueryResponse
            {
                throw $this->exception;
            }

            public function queryRefundStatus(RefundStatusQueryRequest $r): RefundStatusQueryResponse
            {
                throw $this->exception;
            }

            public function parseWebhook(array $p, array $h): ParsedWebhookEvent
            {
                throw $this->exception;
            }

            public function mapStatus(string $s): string
            {
                throw $this->exception;
            }
        };

        $this->app->make(ProviderRegistryInterface::class)->register($adapter);
    }
}
