<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Activity;

use App\Infrastructure\Activity\UpdateRefundStatusActivityImpl;
use App\Infrastructure\Http\PaymentDomainClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateRefundStatusActivityImplTest extends TestCase
{
    private UpdateRefundStatusActivityImpl $activity;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.payment_domain.base_url' => 'http://payment-domain',
            'services.payment_domain.internal_secret' => 'test-secret',
            'services.payment_domain.connect_timeout' => 2,
            'services.payment_domain.timeout' => 5,
        ]);
        $this->activity = new UpdateRefundStatusActivityImpl(new PaymentDomainClient);
    }

    // ── markPendingProvider ──────────────────────────────────────────────────

    public function test_mark_pending_provider_sends_correct_payload(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/refunds/*/status' => Http::response([], 200),
        ]);

        $this->activity->markPendingProvider('ref-uuid-1', 'corr-uuid-1');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://payment-domain/api/internal/v1/refunds/ref-uuid-1/status'
                && $request->method() === 'PATCH'
                && $request['status'] === 'pending_provider'
                && $request['correlation_id'] === 'corr-uuid-1'
                && $request->header('X-Internal-Secret')[0] === 'test-secret';
        });
    }

    // ── markCompleted ────────────────────────────────────────────────────────

    public function test_mark_completed_sends_succeeded_status(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/refunds/*/status' => Http::response([], 200),
        ]);

        $this->activity->markCompleted('ref-uuid-1', 'corr-uuid-1');

        Http::assertSent(fn ($r) => $r['status'] === 'succeeded');
    }

    // ── markFailed ───────────────────────────────────────────────────────────

    public function test_mark_failed_sends_reason_when_provided(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/refunds/*/status' => Http::response([], 200),
        ]);

        $this->activity->markFailed('ref-uuid-1', 'corr-uuid-1', 'provider_declined');

        Http::assertSent(function ($r) {
            return $r['status'] === 'failed'
                && $r['failure_reason'] === 'provider_declined';
        });
    }

    public function test_mark_failed_omits_reason_when_null(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/refunds/*/status' => Http::response([], 200),
        ]);

        $this->activity->markFailed('ref-uuid-1', 'corr-uuid-1');

        Http::assertSent(function ($r) {
            return $r['status'] === 'failed' && ! isset($r['failure_reason']);
        });
    }

    // ── markRequiresReconciliation ───────────────────────────────────────────

    public function test_mark_requires_reconciliation_sends_failed_step(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/refunds/*/status' => Http::response([], 200),
        ]);

        $this->activity->markRequiresReconciliation('ref-uuid-1', 'corr-uuid-1', 'ledger_post_refund');

        Http::assertSent(function ($r) {
            return $r['status'] === 'requires_reconciliation'
                && $r['failed_step'] === 'ledger_post_refund';
        });
    }

    // ── Error handling ────────────────────────────────────────────────────────

    public function test_throws_runtime_exception_on_non_2xx_response(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/refunds/*/status' => Http::response([], 500),
        ]);

        $this->expectException(\RuntimeException::class);

        $this->activity->markPendingProvider('ref-uuid-1', 'corr-uuid-1');
    }

    public function test_throws_runtime_exception_on_connection_failure(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/refunds/*/status' => function () {
                throw new ConnectionException('Connection refused');
            },
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/payment-domain unreachable/');

        $this->activity->markPendingProvider('ref-uuid-1', 'corr-uuid-1');
    }
}
