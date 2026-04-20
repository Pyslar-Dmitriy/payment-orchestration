<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Workflow\PaymentWorkflow;
use Mockery\MockInterface;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Exception\Client\ServiceClientException;
use Temporal\Exception\Client\WorkflowNotFoundException;
use Temporal\Workflow\WorkflowExecution as WorkflowExecutionDTO;
use Tests\TestCase;

class SignalPaymentWorkflowTest extends TestCase
{
    private const WORKFLOW_ID = '550e8400-e29b-41d4-a716-446655440001';

    private const CORRELATION_ID = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    private const INTERNAL_SECRET = 'test-internal-secret';

    private array $validPayload = [
        'signal_name' => 'provider.authorization_result',
        'provider_event_id' => 'evt_abc123',
        'provider_status' => 'authorized',
        'provider_reference' => 'ref_xyz',
        'correlation_id' => self::CORRELATION_ID,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.internal.secret', self::INTERNAL_SECRET);
    }

    // ── auth middleware ─────────────────────────────────────────────────────────

    public function test_returns_401_when_internal_secret_is_missing(): void
    {
        $this->withMockedClient();

        $this->postJson('/api/signals/payments/'.self::WORKFLOW_ID, $this->validPayload)
            ->assertUnauthorized();
    }

    public function test_returns_401_when_internal_secret_is_wrong(): void
    {
        $this->withMockedClient();

        $this->withHeaders(['X-Internal-Secret' => 'wrong-secret'])
            ->postJson('/api/signals/payments/'.self::WORKFLOW_ID, $this->validPayload)
            ->assertUnauthorized();
    }

    // ── happy path ──────────────────────────────────────────────────────────────

    public function test_returns_200_when_authorization_result_signal_is_accepted(): void
    {
        $stub = \Mockery::mock(PaymentWorkflow::class);
        $stub->shouldReceive('onAuthorizationResult')
            ->once()
            ->with(\Mockery::on(function (array $payload) {
                return $payload['provider_event_id'] === 'evt_abc123'
                    && $payload['provider_status'] === 'authorized';
            }));

        $this->mockWorkflowClient(function (MockInterface $client) use ($stub): void {
            $client->shouldReceive('newRunningWorkflowStub')
                ->once()
                ->with(PaymentWorkflow::class, self::WORKFLOW_ID)
                ->andReturn($stub);
        });

        $this->withInternalSecret()
            ->postJson('/api/signals/payments/'.self::WORKFLOW_ID, $this->validPayload)
            ->assertOk()
            ->assertJson(['message' => 'Signal accepted.']);
    }

    public function test_returns_200_when_capture_result_signal_is_accepted(): void
    {
        $payload = array_merge($this->validPayload, [
            'signal_name' => 'provider.capture_result',
            'provider_status' => 'captured',
        ]);

        $stub = \Mockery::mock(PaymentWorkflow::class);
        $stub->shouldReceive('onCaptureResult')
            ->once()
            ->with(\Mockery::on(fn (array $p) => $p['provider_status'] === 'captured'));

        $this->mockWorkflowClient(function (MockInterface $client) use ($stub): void {
            $client->shouldReceive('newRunningWorkflowStub')
                ->once()
                ->andReturn($stub);
        });

        $this->withInternalSecret()
            ->postJson('/api/signals/payments/'.self::WORKFLOW_ID, $payload)
            ->assertOk();
    }

    public function test_signal_payload_includes_nullable_provider_reference(): void
    {
        $payload = array_merge($this->validPayload, ['provider_reference' => null]);

        $stub = \Mockery::mock(PaymentWorkflow::class);
        $stub->shouldReceive('onAuthorizationResult')
            ->once()
            ->with(\Mockery::on(fn (array $p) => array_key_exists('provider_reference', $p) && $p['provider_reference'] === null));

        $this->mockWorkflowClient(function (MockInterface $client) use ($stub): void {
            $client->shouldReceive('newRunningWorkflowStub')->once()->andReturn($stub);
        });

        $this->withInternalSecret()
            ->postJson('/api/signals/payments/'.self::WORKFLOW_ID, $payload)
            ->assertOk();
    }

    // ── workflow not found ────────────────────────────────────────────────────

    public function test_returns_404_when_workflow_not_found_exception_is_thrown(): void
    {
        $this->mockWorkflowClient(function (MockInterface $client): void {
            $client->shouldReceive('newRunningWorkflowStub')
                ->andReturn(\Mockery::mock(PaymentWorkflow::class, function (MockInterface $stub): void {
                    $stub->shouldReceive('onAuthorizationResult')
                        ->andThrow(new WorkflowNotFoundException(
                            'not found',
                            new WorkflowExecutionDTO(self::WORKFLOW_ID),
                            'PaymentWorkflow',
                        ));
                }));
        });

        $this->withInternalSecret()
            ->postJson('/api/signals/payments/'.self::WORKFLOW_ID, $this->validPayload)
            ->assertNotFound()
            ->assertJson(['message' => 'Workflow not found.', 'reason' => 'workflow_not_found']);
    }

    public function test_returns_404_with_workflow_already_closed_reason_when_service_client_returns_grpc_not_found(): void
    {
        $this->mockWorkflowClient(function (MockInterface $client): void {
            $client->shouldReceive('newRunningWorkflowStub')
                ->andReturn(\Mockery::mock(PaymentWorkflow::class, function (MockInterface $stub): void {
                    $stub->shouldReceive('onAuthorizationResult')
                        ->andThrow($this->makeNotFoundServiceClientException());
                }));
        });

        $this->withInternalSecret()
            ->postJson('/api/signals/payments/'.self::WORKFLOW_ID, $this->validPayload)
            ->assertNotFound()
            ->assertJson(['reason' => 'workflow_already_closed']);
    }

    // ── route constraint ──────────────────────────────────────────────────────

    public function test_returns_404_when_workflow_id_is_not_a_uuid(): void
    {
        $this->withMockedClient();

        $this->withInternalSecret()
            ->postJson('/api/signals/payments/not-a-uuid', $this->validPayload)
            ->assertNotFound();
    }

    // ── validation ────────────────────────────────────────────────────────────

    public function test_returns_422_when_signal_name_is_missing(): void
    {
        $this->withMockedClient();

        $payload = $this->validPayload;
        unset($payload['signal_name']);

        $this->withInternalSecret()
            ->postJson('/api/signals/payments/'.self::WORKFLOW_ID, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signal_name']);
    }

    public function test_returns_422_when_signal_name_is_invalid(): void
    {
        $this->withMockedClient();

        $this->withInternalSecret()
            ->postJson('/api/signals/payments/'.self::WORKFLOW_ID, array_merge($this->validPayload, [
                'signal_name' => 'provider.refund_result',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signal_name']);
    }

    public function test_returns_422_when_provider_event_id_is_missing(): void
    {
        $this->withMockedClient();

        $payload = $this->validPayload;
        unset($payload['provider_event_id']);

        $this->withInternalSecret()
            ->postJson('/api/signals/payments/'.self::WORKFLOW_ID, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider_event_id']);
    }

    public function test_returns_422_when_provider_status_is_missing(): void
    {
        $this->withMockedClient();

        $payload = $this->validPayload;
        unset($payload['provider_status']);

        $this->withInternalSecret()
            ->postJson('/api/signals/payments/'.self::WORKFLOW_ID, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider_status']);
    }

    public function test_returns_422_when_correlation_id_is_not_a_uuid(): void
    {
        $this->withMockedClient();

        $this->withInternalSecret()
            ->postJson('/api/signals/payments/'.self::WORKFLOW_ID, array_merge($this->validPayload, [
                'correlation_id' => 'not-a-uuid',
            ]))
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

    private function withMockedClient(): void
    {
        $this->app->instance(WorkflowClientInterface::class, \Mockery::mock(WorkflowClientInterface::class));
    }

    private function withInternalSecret(): static
    {
        return $this->withHeaders(['X-Internal-Secret' => self::INTERNAL_SECRET]);
    }

    private function makeNotFoundServiceClientException(): ServiceClientException
    {
        $status = new \stdClass;
        $status->code = 5; // gRPC NOT_FOUND
        $status->details = 'workflow execution not found';
        $status->metadata = [];

        return new ServiceClientException($status);
    }
}
