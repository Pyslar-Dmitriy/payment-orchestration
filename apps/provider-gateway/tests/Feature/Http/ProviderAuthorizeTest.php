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

class ProviderAuthorizeTest extends TestCase
{
    use RegistersFakeAdapter;

    private string $paymentUuid = '550e8400-e29b-41d4-a716-446655440000';

    private string $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_returns_sync_captured_result(): void
    {
        $this->registerFakeAdapter(
            authorizeResponse: new AuthorizeResponse('fake-ref-001', 'captured', false, true),
        );

        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(200)
            ->assertExactJson([
                'provider_reference' => 'fake-ref-001',
                'provider_status' => 'captured',
                'is_async' => false,
            ]);
    }

    public function test_returns_async_result_when_adapter_says_async(): void
    {
        $this->registerFakeAdapter(
            authorizeResponse: new AuthorizeResponse('fake-ref-async', 'pending', true, false),
        );

        $response = $this->postJson('/api/v1/provider/authorize', [
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

    public function test_calls_capture_when_authorize_returns_authorized_synchronously(): void
    {
        // Adapter returns "authorized" (not captured) synchronously — handler must call capture.
        $this->registerFakeAdapter(
            authorizeResponse: new AuthorizeResponse('fake-ref-auth', 'authorized', false, false),
        );

        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
        ]);

        // The fake adapter's capture() returns 'captured' with the same provider_reference.
        $response->assertStatus(200)
            ->assertJsonFragment([
                'provider_reference' => 'fake-ref-auth',
                'provider_status' => 'captured',
                'is_async' => false,
            ]);
    }

    public function test_accepts_optional_amount_currency_country_fields(): void
    {
        $this->registerFakeAdapter();

        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
            'amount' => 5000,
            'currency' => 'EUR',
            'country' => 'DE',
        ]);

        $response->assertStatus(200);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_returns_422_when_payment_uuid_is_missing(): void
    {
        $response = $this->postJson('/api/v1/provider/authorize', [
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_uuid']);
    }

    public function test_returns_422_when_payment_uuid_is_not_a_uuid(): void
    {
        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => 'not-a-uuid',
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_uuid']);
    }

    public function test_returns_422_when_provider_key_is_missing(): void
    {
        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['provider_key']);
    }

    public function test_returns_422_when_correlation_id_is_missing(): void
    {
        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['correlation_id']);
    }

    public function test_returns_422_when_amount_is_not_an_integer(): void
    {
        $this->registerFakeAdapter();

        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
            'amount' => 'not-a-number',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    // ── Error paths ───────────────────────────────────────────────────────────

    public function test_returns_422_when_provider_key_is_not_registered(): void
    {
        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'nonexistent',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => "No adapter registered for provider key 'nonexistent'."]);
    }

    public function test_returns_422_when_adapter_throws_hard_failure(): void
    {
        $adapter = $this->makeFakeAdapterThatThrows(new ProviderHardFailureException('Card declined', 'do_not_honor'));
        $this->app->make(ProviderRegistryInterface::class)->register($adapter);

        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Provider declined the request.', 'provider_code' => 'do_not_honor']);
    }

    public function test_returns_503_when_adapter_throws_transient_failure(): void
    {
        $adapter = $this->makeFakeAdapterThatThrows(new ProviderTransientException('Connection timeout'));
        $this->app->make(ProviderRegistryInterface::class)->register($adapter);

        $response = $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'fake',
            'correlation_id' => $this->correlationId,
        ]);

        $response->assertStatus(503)
            ->assertJsonFragment(['message' => 'Provider temporarily unavailable.']);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function makeFakeAdapterThatThrows(\Throwable $exception): ProviderAdapterInterface
    {
        return new class($exception) implements ProviderAdapterInterface
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
    }
}
