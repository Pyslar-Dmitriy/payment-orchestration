<?php

namespace App\Application\Payment;

use App\Application\Payment\DTO\InternalUpdatePaymentStatusCommand;
use App\Application\Payment\DTO\UpdatePaymentStatusResult;
use App\Domain\Payment\Exceptions\InvalidPaymentTransitionException;
use App\Domain\Payment\Exceptions\PaymentConcurrencyException;
use App\Domain\Payment\Exceptions\PaymentNotFoundException;
use App\Domain\Payment\Payment;
use App\Domain\Payment\PaymentStatus;
use App\Infrastructure\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;

/**
 * Internal-only use case for payment status transitions triggered by the orchestrator.
 * Looks up the payment by UUID without merchant scoping — callers must be trusted
 * (enforced at the route level via InternalServiceMiddleware).
 */
final class InternalMarkPaymentStatus
{
    /**
     * @throws PaymentNotFoundException
     * @throws InvalidPaymentTransitionException
     * @throws PaymentConcurrencyException
     */
    public function execute(
        InternalUpdatePaymentStatusCommand $command,
        PaymentStatus $status,
    ): UpdatePaymentStatusResult {
        $payment = Payment::where('id', $command->paymentId)->first();

        if ($payment === null) {
            throw new PaymentNotFoundException($command->paymentId);
        }

        return DB::transaction(function () use ($payment, $command, $status): UpdatePaymentStatusResult {
            $payment->transition(
                $status,
                $command->correlationId,
                failureCode: $command->failureCode,
                failureReason: $command->failureReason,
            );

            $eventType = $this->resolveEventType($status);
            $payload = $this->buildPayload($payment, $command, $status);

            OutboxEvent::create([
                'aggregate_type' => 'Payment',
                'aggregate_id' => $payment->id,
                'event_type' => $eventType,
                'payload' => $payload,
            ]);

            return new UpdatePaymentStatusResult(
                paymentId: $payment->id,
                status: $payment->status->value,
            );
        });
    }

    private function resolveEventType(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::PENDING_PROVIDER => 'payment.pending_provider.v1',
            PaymentStatus::AUTHORIZED => 'payment.authorized.v1',
            PaymentStatus::CAPTURED => 'payment.captured.v1',
            PaymentStatus::FAILED => 'payment.failed.v1',
            PaymentStatus::REQUIRES_RECONCILIATION => 'payment.requires_reconciliation.v1',
            default => throw new \LogicException("Unexpected internal payment status: {$status->value}"),
        };
    }

    private function buildPayload(Payment $payment, InternalUpdatePaymentStatusCommand $command, PaymentStatus $status): array
    {
        $base = [
            'payment_id' => $payment->id,
            'merchant_id' => $payment->merchant_id,
            'provider_id' => $payment->provider_id,
            'amount' => ['value' => $payment->amount, 'currency' => $payment->currency],
            'status' => $payment->status->value,
            'correlation_id' => $command->correlationId,
            'occurred_at' => now()->toIso8601String(),
        ];

        if ($status === PaymentStatus::FAILED) {
            $base['failure_code'] = $payment->failure_code;
            $base['failure_reason'] = $payment->failure_reason;
        }

        if ($status === PaymentStatus::REQUIRES_RECONCILIATION) {
            $base['failed_step'] = $command->failedStep;
        }

        return $base;
    }
}
