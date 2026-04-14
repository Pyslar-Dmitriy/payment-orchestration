<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Workflow\RefundWorkflow;
use Mockery\MockInterface;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;
use Temporal\Workflow\WorkflowExecution as WorkflowExecutionDTO;
use Tests\TestCase;

class StartRefundWorkflowTest extends TestCase
{
    private const REFUND_UUID = '550e8400-e29b-41d4-a716-446655440010';

    private const PAYMENT_UUID = '550e8400-e29b-41d4-a716-446655440001';

    private const MERCHANT_ID = '550e8400-e29b-41d4-a716-446655440002';

    private const CORRELATION_ID = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    private array $validPayload = [
        'refund_uuid' => self::REFUND_UUID,
        'payment_uuid' => self::PAYMENT_UUID,
        'merchant_id' => self::MERCHANT_ID,
        'amount' => 5000,
        'currency' => 'USD',
        'provider_key' => 'stripe',
        'correlation_id' => self::CORRELATION_ID,
    ];

    // ── happy path ──────────────────────────────────────────────────────────────

    public function test_returns_201_with_workflow_id_on_success(): void
    {
        $stub = \Mockery::mock();
        $stub->shouldReceive('run')->never();

        $this->mockWorkflowClient(function (MockInterface $client) use ($stub): void {
            $client->shouldReceive('newWorkflowStub')
                ->once()
                ->withArgs(function (string $class, WorkflowOptions $opts) {
                    return $class === RefundWorkflow::class
                        && $opts->workflowId === self::REFUND_UUID;
                })
                ->andReturn($stub);

            $client->shouldReceive('start')
                ->once()
                ->with($stub, \Mockery::type('App\Domain\DTO\RefundWorkflowInput'));
        });

        $response = $this->postJson('/api/workflows/refunds', $this->validPayload);

        $response->assertCreated()->assertJson(['workflow_id' => self::REFUND_UUID]);
    }

    // ── duplicate workflow ────────────────────────────────────────────────────

    public function test_returns_409_when_workflow_is_already_running(): void
    {
        $stub = \Mockery::mock();

        $this->mockWorkflowClient(function (MockInterface $client) use ($stub): void {
            $client->shouldReceive('newWorkflowStub')->andReturn($stub);
            $client->shouldReceive('start')->andThrow(
                new WorkflowExecutionAlreadyStartedException(
                    new WorkflowExecutionDTO(self::REFUND_UUID),
                    'RefundWorkflow',
                ),
            );
        });

        $response = $this->postJson('/api/workflows/refunds', $this->validPayload);

        $response->assertConflict();
    }

    // ── validation ────────────────────────────────────────────────────────────

    public function test_returns_422_when_refund_uuid_is_missing(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/workflows/refunds', array_merge($this->validPayload, ['refund_uuid' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund_uuid']);
    }

    public function test_returns_422_when_refund_uuid_is_not_a_uuid(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/workflows/refunds', array_merge($this->validPayload, ['refund_uuid' => 'not-a-uuid']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['refund_uuid']);
    }

    public function test_returns_422_when_payment_uuid_is_missing(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/workflows/refunds', array_merge($this->validPayload, ['payment_uuid' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_uuid']);
    }

    public function test_returns_422_when_amount_is_zero(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/workflows/refunds', array_merge($this->validPayload, ['amount' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_returns_422_when_amount_is_negative(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/workflows/refunds', array_merge($this->validPayload, ['amount' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_returns_422_when_currency_is_wrong_length(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/workflows/refunds', array_merge($this->validPayload, ['currency' => 'US']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_returns_422_when_provider_key_is_missing(): void
    {
        $this->withMockedClient();

        $payload = $this->validPayload;
        unset($payload['provider_key']);

        $this->postJson('/api/workflows/refunds', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider_key']);
    }

    public function test_returns_422_when_correlation_id_is_missing(): void
    {
        $this->withMockedClient();

        $payload = $this->validPayload;
        unset($payload['correlation_id']);

        $this->postJson('/api/workflows/refunds', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['correlation_id']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function mockWorkflowClient(callable $configure): void
    {
        $mock = \Mockery::mock(WorkflowClientInterface::class);
        $configure($mock);

        $this->app->instance(WorkflowClientInterface::class, $mock);
    }

    /** Bind a permissive mock so the DI container can resolve the controller. */
    private function withMockedClient(): void
    {
        $this->app->instance(WorkflowClientInterface::class, \Mockery::mock(WorkflowClientInterface::class));
    }
}