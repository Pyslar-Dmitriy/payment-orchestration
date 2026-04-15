<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Activity;

use App\Infrastructure\Activity\UpdatePaymentStatusActivityImpl;
use App\Infrastructure\Http\PaymentDomainClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdatePaymentStatusActivityImplTest extends TestCase
{
    private UpdatePaymentStatusActivityImpl $activity;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.payment_domain.base_url' => 'http://payment-domain',
            'services.payment_domain.internal_secret' => 'test-secret',
            'services.payment_domain.connect_timeout' => 2,
            'services.payment_domain.timeout' => 5,
        ]);
        $this->activity = new UpdatePaymentStatusActivityImpl(new PaymentDomainClient);
    }

    // ── markPendingProvider ──────────────────────────────────────────────────

    public function test_mark_pending_provider_sends_correct_payload(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/payments/*/status' => Http::response(['status' => 'pending_provider'], 200),
        ]);

        $this->activity->markPendingProvider('pay-uuid-1', 'corr-uuid-1');

        Http::assertSent(function ($request) {
            return $request->url() === 'http://payment-domain/api/internal/v1/payments/pay-uuid-1/status'
                && $request->method() === 'PATCH'
                && $request['status'] === 'pending_provider'
                && $request['correlation_id'] === 'corr-uuid-1'
                && $request->header('X-Internal-Secret')[0] === 'test-secret';
        });
    }

    // ── markAuthorized ───────────────────────────────────────────────────────

    public function test_mark_authorized_sends_correct_payload(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/payments/*/status' => Http::response([], 200),
        ]);

        $this->activity->markAuthorized('pay-uuid-1', 'corr-uuid-1');

        Http::assertSent(fn ($r) => $r['status'] === 'authorized');
    }

    // ── markCaptured ─────────────────────────────────────────────────────────

    public function test_mark_captured_sends_correct_payload(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/payments/*/status' => Http::response([], 200),
        ]);

        $this->activity->markCaptured('pay-uuid-1', 'corr-uuid-1');

        Http::assertSent(fn ($r) => $r['status'] === 'captured');
    }

    // ── markFailed ───────────────────────────────────────────────────────────

    public function test_mark_failed_sends_reason_when_provided(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/payments/*/status' => Http::response([], 200),
        ]);

        $this->activity->markFailed('pay-uuid-1', 'corr-uuid-1', 'insufficient_funds');

        Http::assertSent(function ($r) {
            return $r['status'] === 'failed'
                && $r['failure_reason'] === 'insufficient_funds';
        });
    }

    public function test_mark_failed_omits_reason_when_null(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/payments/*/status' => Http::response([], 200),
        ]);

        $this->activity->markFailed('pay-uuid-1', 'corr-uuid-1');

        Http::assertSent(function ($r) {
            return $r['status'] === 'failed'
                && ! isset($r['failure_reason']);
        });
    }

    // ── markRequiresReconciliation ───────────────────────────────────────────

    public function test_mark_requires_reconciliation_sends_failed_step(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/payments/*/status' => Http::response([], 200),
        ]);

        $this->activity->markRequiresReconciliation('pay-uuid-1', 'corr-uuid-1', 'ledger_post');

        Http::assertSent(function ($r) {
            return $r['status'] === 'requires_reconciliation'
                && $r['failed_step'] === 'ledger_post';
        });
    }

    // ── Error handling ────────────────────────────────────────────────────────

    public function test_throws_runtime_exception_on_non_2xx_response(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/payments/*/status' => Http::response([], 500),
        ]);

        $this->expectException(\RuntimeException::class);

        $this->activity->markPendingProvider('pay-uuid-1', 'corr-uuid-1');
    }

    public function test_throws_runtime_exception_on_connection_failure(): void
    {
        Http::fake([
            'http://payment-domain/api/internal/v1/payments/*/status' => function () {
                throw new ConnectionException('Connection refused');
            },
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/payment-domain unreachable/');

        $this->activity->markPendingProvider('pay-uuid-1', 'corr-uuid-1');
    }
}
