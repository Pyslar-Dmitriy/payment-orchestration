<?php

namespace Tests\Feature\Refund;

use App\Domain\Refund\Refund;
use App\Domain\Refund\RefundStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InternalTransitionRefundStatusTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-internal-secret';

    private string $correlationId = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.internal.secret' => $this->secret]);
    }

    private function createRefund(RefundStatus $status): Refund
    {
        return Refund::create([
            'payment_id' => '01JREFERENCEPAYMENTID00000',
            'merchant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'amount' => 1000,
            'currency' => 'USD',
            'status' => $status,
            'correlation_id' => $this->correlationId,
        ]);
    }

    private function patchStatus(string $refundId, array $payload, ?string $secret = null): TestResponse
    {
        return $this->patchJson( // @phpstan-ignore-line
            "/api/internal/v1/refunds/{$refundId}/status",
            $payload,
            ['X-Internal-Secret' => $secret ?? $this->secret],
        );
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public function test_returns_401_when_secret_is_missing(): void
    {
        $this->patchJson('/api/internal/v1/refunds/00000000000000000000000000/status', [ // @phpstan-ignore-line
            'status' => 'pending_provider',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(401);
    }

    public function test_returns_401_when_secret_is_wrong(): void
    {
        $this->patchStatus('00000000000000000000000000', [
            'status' => 'pending_provider',
            'correlation_id' => $this->correlationId,
        ], 'bad-secret')->assertStatus(401);
    }

    // ── Status transitions ────────────────────────────────────────────────────

    public function test_marks_refund_pending_provider(): void
    {
        $refund = $this->createRefund(RefundStatus::PENDING);

        $this->patchStatus($refund->id, [
            'status' => 'pending_provider',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(200)->assertJsonFragment(['status' => 'pending_provider']);

        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'pending_provider']);
    }

    public function test_marks_refund_succeeded(): void
    {
        $refund = $this->createRefund(RefundStatus::PENDING_PROVIDER);

        $this->patchStatus($refund->id, [
            'status' => 'succeeded',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(200)->assertJsonFragment(['status' => 'succeeded']);
    }

    public function test_marks_refund_failed_with_reason(): void
    {
        $refund = $this->createRefund(RefundStatus::PENDING_PROVIDER);

        $this->patchStatus($refund->id, [
            'status' => 'failed',
            'correlation_id' => $this->correlationId,
            'failure_reason' => 'Provider declined.',
        ])->assertStatus(200)->assertJsonFragment(['status' => 'failed']);

        $this->assertDatabaseHas('refunds', [
            'id' => $refund->id,
            'failure_reason' => 'Provider declined.',
        ]);
    }

    public function test_marks_refund_requires_reconciliation(): void
    {
        $refund = $this->createRefund(RefundStatus::PENDING_PROVIDER);

        $this->patchStatus($refund->id, [
            'status' => 'requires_reconciliation',
            'correlation_id' => $this->correlationId,
            'failed_step' => 'ledger_post_refund',
        ])->assertStatus(200)->assertJsonFragment(['status' => 'requires_reconciliation']);

        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'requires_reconciliation']);
    }

    // ── Outbox events ─────────────────────────────────────────────────────────

    public function test_pending_provider_writes_outbox_event(): void
    {
        $refund = $this->createRefund(RefundStatus::PENDING);

        $this->patchStatus($refund->id, [
            'status' => 'pending_provider',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(200);

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $refund->id,
            'event_type' => 'refund.pending_provider.v1',
        ]);
    }

    public function test_succeeded_writes_outbox_event(): void
    {
        $refund = $this->createRefund(RefundStatus::PENDING_PROVIDER);

        $this->patchStatus($refund->id, [
            'status' => 'succeeded',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(200);

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $refund->id,
            'event_type' => 'refund.succeeded.v1',
        ]);
    }

    // ── Error cases ───────────────────────────────────────────────────────────

    public function test_returns_404_when_refund_not_found(): void
    {
        $this->patchStatus('00000000000000000000000000', [
            'status' => 'pending_provider',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(404);
    }

    public function test_returns_422_when_transition_is_invalid(): void
    {
        $refund = $this->createRefund(RefundStatus::FAILED);

        $this->patchStatus($refund->id, [
            'status' => 'succeeded',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(422);
    }

    public function test_returns_422_when_status_is_not_allowed_for_internal(): void
    {
        $refund = $this->createRefund(RefundStatus::PENDING);

        // 'pending' is not in the internal status list (it's the initial state)
        $this->patchStatus($refund->id, [
            'status' => 'pending',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_validates_required_fields(): void
    {
        $this->patchJson( // @phpstan-ignore-line
            '/api/internal/v1/refunds/00000000000000000000000000/status',
            [],
            ['X-Internal-Secret' => $this->secret],
        )->assertStatus(422)->assertJsonValidationErrors(['status', 'correlation_id']);
    }
}
