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
use App\Domain\Provider\Exception\ProviderHardFailureException;
use App\Domain\Provider\Exception\ProviderTransientException;
use App\Domain\Provider\ProviderAdapterInterface;
use App\Domain\Provider\ProviderRegistryInterface;
use Tests\Feature\Http\Concerns\RegistersFakeAdapter;
use Tests\TestCase;

class ProviderRefundTest extends TestCase
{
    use RegistersFakeAdapter;

    private string $refundUuid = '7f3e4a10-9dad-11d1-80b4-00c04fd430c8';

    private string $paymentUuid = '550e8400-e29b-41d4-a716-446655440000';

    private string $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_returns_sync_refunded_result(): void
    {
        $this->registerFakeAdapter(
            refundResponse: new RefundResponse('fake-ref-002', 'refunded', false),
        );

        $response = $this->postJson('/api/v1/provider/refund', [
            'refund_uuid' => $this->refundUuid,
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(200)
            ->assertExactJson([
                'provider_reference' => 'fake-ref-002',
                'provider_status' => 'refunded',
                'is_async' => false,
            ]);
    }

    public function test_returns_async_result_when_adapter_says_async(): void
    {
        $this->registerFakeAdapter(
            refundResponse: new RefundResponse('fake-ref-async', 'pending', true),
        );

        $response = $this->postJson('/api/v1/provider/refund', [
            'refund_uuid' => $this->refundUuid,
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(200)
            ->assertExactJson([
                'provider_reference' => 'fake-ref-async',
                'provider_status' => 'pending',
                'is_async' => true,
            ]);
    }

    public function test_accepts_optional_provider_reference_amount_currency(): void
    {
        $this->registerFakeAdapter();

        $response = $this->postJson('/api/v1/provider/refund', [
            'refund_uuid' => $this->refundUuid,
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
            'provider_reference' => 'psp-txn-abc',
            'amount' => 1000,
            'currency' => 'USD',
        ]);

        $response->assertStatus(200);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_returns_422_when_refund_uuid_is_missing(): void
    {
        $response = $this->postJson('/api/v1/provider/refund', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['refund_uuid']);
    }

    public function test_returns_422_when_payment_uuid_is_not_a_uuid(): void
    {
        $response = $this->postJson('/api/v1/provider/refund', [
            'refund_uuid' => $this->refundUuid,
            'payment_uuid' => 'not-a-uuid',
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_uuid']);
    }

    public function test_returns_422_when_provider_key_is_missing(): void
    {
        $response = $this->postJson('/api/v1/provider/refund', [
            'refund_uuid' => $this->refundUuid,
            'payment_uuid' => $this->paymentUuid,
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['provider_key']);
    }

    // ── Error paths ───────────────────────────────────────────────────────────

    public function test_returns_422_when_provider_key_is_not_registered(): void
    {
        $response = $this->postJson('/api/v1/provider/refund', [
            'refund_uuid' => $this->refundUuid,
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'nonexistent',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => "No adapter registered for provider key 'nonexistent'."]);
    }

    public function test_returns_422_when_adapter_throws_hard_failure(): void
    {
        $this->registerAdapterThatThrows(new ProviderHardFailureException('Refund not permitted', 'refund_not_allowed'));

        $response = $this->postJson('/api/v1/provider/refund', [
            'refund_uuid' => $this->refundUuid,
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Provider declined the refund.', 'provider_code' => 'refund_not_allowed']);
    }

    public function test_returns_503_when_adapter_throws_transient_failure(): void
    {
        $this->registerAdapterThatThrows(new ProviderTransientException('PSP timeout'));

        $response = $this->postJson('/api/v1/provider/refund', [
            'refund_uuid' => $this->refundUuid,
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
        ]);

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
