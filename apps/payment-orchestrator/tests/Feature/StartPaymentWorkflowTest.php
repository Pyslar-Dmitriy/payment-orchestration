<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Workflow\PaymentWorkflow;
use Mockery\MockInterface;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;
use Temporal\Workflow\WorkflowExecution as WorkflowExecutionDTO;
use Tests\TestCase;

class StartPaymentWorkflowTest extends TestCase
{
    private const PAYMENT_UUID = '550e8400-e29b-41d4-a716-446655440001';

    private const MERCHANT_ID = '550e8400-e29b-41d4-a716-446655440002';

    private const CORRELATION_ID = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    private array $validPayload = [
        'payment_uuid' => self::PAYMENT_UUID,
        'merchant_id' => self::MERCHANT_ID,
        'amount' => 9900,
        'currency' => 'USD',
        'country' => 'US',
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
                    return $class === PaymentWorkflow::class
                        && $opts->workflowId === self::PAYMENT_UUID;
                })
                ->andReturn($stub);

            $client->shouldReceive('start')
                ->once()
                ->with($stub, \Mockery::type('App\Domain\DTO\PaymentWorkflowInput'));
        });

        $response = $this->postJson('/api/workflows/payments', $this->validPayload);

        $response->assertCreated()->assertJson(['workflow_id' => self::PAYMENT_UUID]);
    }

    // ── duplicate workflow ────────────────────────────────────────────────────

    public function test_returns_409_when_workflow_is_already_running(): void
    {
        $stub = \Mockery::mock();

        $this->mockWorkflowClient(function (MockInterface $client) use ($stub): void {
            $client->shouldReceive('newWorkflowStub')->andReturn($stub);
            $client->shouldReceive('start')->andThrow(
                new WorkflowExecutionAlreadyStartedException(
                    new WorkflowExecutionDTO(self::PAYMENT_UUID),
                    'PaymentWorkflow',
                ),
            );
        });

        $response = $this->postJson('/api/workflows/payments', $this->validPayload);

        $response->assertConflict();
    }

    // ── validation ────────────────────────────────────────────────────────────

    public function test_returns_422_when_payment_uuid_is_missing(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/workflows/payments', array_merge($this->validPayload, ['payment_uuid' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_uuid']);
    }

    public function test_returns_422_when_payment_uuid_is_not_a_uuid(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/workflows/payments', array_merge($this->validPayload, ['payment_uuid' => 'not-a-uuid']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_uuid']);
    }

    public function test_returns_422_when_amount_is_zero(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/workflows/payments', array_merge($this->validPayload, ['amount' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_returns_422_when_amount_is_negative(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/workflows/payments', array_merge($this->validPayload, ['amount' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_returns_422_when_currency_is_wrong_length(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/workflows/payments', array_merge($this->validPayload, ['currency' => 'US']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_returns_422_when_country_is_wrong_length(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/workflows/payments', array_merge($this->validPayload, ['country' => 'USA']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['country']);
    }

    public function test_returns_422_when_correlation_id_is_missing(): void
    {
        $this->withMockedClient();

        $payload = $this->validPayload;
        unset($payload['correlation_id']);

        $this->postJson('/api/workflows/payments', $payload)
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
