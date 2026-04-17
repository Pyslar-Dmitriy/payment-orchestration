<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Infrastructure\Provider\Audit\ProviderAuditLog;
use App\Infrastructure\Provider\Mock\MockScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that provider adapter calls are persisted to provider_audit_logs.
 *
 * Uses the real MockProviderAdapter registered (and wrapped) in AppServiceProvider
 * so the full HTTP → handler → AuditingProviderAdapter → MockProviderAdapter path
 * is exercised.
 */
class ProviderAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private string $paymentUuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

    private string $refundUuid = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';

    private string $correlationId = 'c3d4e5f6-a7b8-9012-cdef-123456789012';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('mock_provider.scenario', MockScenario::Success->value);
        config()->set('mock_provider.webhook_url', null);
    }

    // ── authorize: success ────────────────────────────────────────────────────

    public function test_authorize_success_creates_audit_log_record(): void
    {
        $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
            'amount' => 5000,
            'currency' => 'EUR',
            'country' => 'DE',
        ])->assertStatus(200);

        $this->assertDatabaseCount('provider_audit_logs', 1);

        $log = ProviderAuditLog::first();

        $this->assertSame('mock', $log->provider_key);
        $this->assertSame('authorize', $log->operation);
        $this->assertSame($this->paymentUuid, $log->payment_uuid);
        $this->assertNull($log->refund_uuid);
        $this->assertSame($this->correlationId, $log->correlation_id);
        $this->assertSame('success', $log->outcome);
        $this->assertNull($log->error_code);
        $this->assertNull($log->error_message);
        $this->assertGreaterThanOrEqual(0, $log->duration_ms);

        $this->assertSame($this->paymentUuid, $log->request_payload['payment_uuid']);
        $this->assertSame(5000, $log->request_payload['amount']);
        $this->assertSame('EUR', $log->request_payload['currency']);
        $this->assertSame('DE', $log->request_payload['country']);

        $this->assertNotNull($log->response_payload);
        $this->assertSame('captured', $log->response_payload['provider_status']);
    }

    // ── authorize: hard failure ───────────────────────────────────────────────

    public function test_authorize_hard_failure_creates_audit_log_with_hard_failure_outcome(): void
    {
        config()->set('mock_provider.scenario', MockScenario::HardFailure->value);

        $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(422);

        $this->assertDatabaseCount('provider_audit_logs', 1);

        $log = ProviderAuditLog::first();

        $this->assertSame('mock', $log->provider_key);
        $this->assertSame('authorize', $log->operation);
        $this->assertSame('hard_failure', $log->outcome);
        $this->assertSame('mock_declined', $log->error_code);
        $this->assertNotNull($log->error_message);
        $this->assertNull($log->response_payload);
    }

    // ── authorize: transient failure ─────────────────────────────────────────

    public function test_authorize_transient_failure_creates_audit_log_with_transient_outcome(): void
    {
        config()->set('mock_provider.scenario', MockScenario::Timeout->value);

        $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(503);

        $this->assertDatabaseCount('provider_audit_logs', 1);

        $log = ProviderAuditLog::first();

        $this->assertSame('transient_failure', $log->outcome);
        $this->assertNull($log->error_code);
        $this->assertNotNull($log->error_message);
        $this->assertNull($log->response_payload);
    }

    // ── refund ────────────────────────────────────────────────────────────────

    public function test_refund_success_creates_audit_log_record(): void
    {
        $this->postJson('/api/v1/provider/refund', [
            'refund_uuid' => $this->refundUuid,
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
            'amount' => 2000,
            'currency' => 'USD',
        ])->assertStatus(200);

        $this->assertDatabaseCount('provider_audit_logs', 1);

        $log = ProviderAuditLog::first();

        $this->assertSame('refund', $log->operation);
        $this->assertSame($this->paymentUuid, $log->payment_uuid);
        $this->assertSame($this->refundUuid, $log->refund_uuid);
        $this->assertSame('success', $log->outcome);
        $this->assertSame('refunded', $log->response_payload['provider_status']);
    }

    // ── payment status query ──────────────────────────────────────────────────

    public function test_query_payment_status_creates_audit_log_record(): void
    {
        $this->getJson(
            '/api/v1/provider/payments/'.$this->paymentUuid.'/status'
            .'?provider_key=mock&correlation_id='.$this->correlationId
        )->assertStatus(200);

        $this->assertDatabaseCount('provider_audit_logs', 1);

        $log = ProviderAuditLog::first();

        $this->assertSame('query_payment_status', $log->operation);
        $this->assertSame($this->paymentUuid, $log->payment_uuid);
        $this->assertNull($log->refund_uuid);
        $this->assertSame('success', $log->outcome);
        $this->assertTrue($log->response_payload['is_captured']);
    }

    // ── refund status query ───────────────────────────────────────────────────

    public function test_query_refund_status_creates_audit_log_record(): void
    {
        $this->getJson(
            '/api/v1/provider/refunds/'.$this->refundUuid.'/status'
            .'?provider_key=mock&correlation_id='.$this->correlationId
        )->assertStatus(200);

        $this->assertDatabaseCount('provider_audit_logs', 1);

        $log = ProviderAuditLog::first();

        $this->assertSame('query_refund_status', $log->operation);
        $this->assertNull($log->payment_uuid);
        $this->assertSame($this->refundUuid, $log->refund_uuid);
        $this->assertSame('success', $log->outcome);
        $this->assertTrue($log->response_payload['is_refunded']);
    }

    // ── timestamps and latency ────────────────────────────────────────────────

    public function test_audit_log_records_timestamps_and_duration(): void
    {
        // Truncate to second because the DB column has second-level precision (TIMESTAMP(0)).
        $before = now()->startOfSecond();

        $this->postJson('/api/v1/provider/authorize', [
            'payment_uuid' => $this->paymentUuid,
            'provider_key' => 'mock',
            'correlation_id' => $this->correlationId,
        ])->assertStatus(200);

        $after = now()->addSecond();

        $log = ProviderAuditLog::first();

        $this->assertNotNull($log->requested_at);
        $this->assertNotNull($log->responded_at);
        $this->assertNotNull($log->created_at);
        $this->assertGreaterThanOrEqual(0, $log->duration_ms);
        $this->assertTrue($log->requested_at->greaterThanOrEqualTo($before));
        $this->assertTrue($log->responded_at->lessThanOrEqualTo($after));
    }
}
