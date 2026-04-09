<?php

namespace App\Domain\Payment;

use App\Domain\Payment\Exceptions\InvalidPaymentTransitionException;
use App\Domain\Payment\Exceptions\PaymentConcurrencyException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

final class Payment extends Model
{
    use HasUlids;

    protected $table = 'payments';

    protected $fillable = [
        'merchant_id',
        'external_reference',
        'idempotency_key',
        'customer_reference',
        'payment_method_reference',
        'amount',
        'currency',
        'status',
        'provider_id',
        'provider_transaction_id',
        'failure_code',
        'failure_reason',
        'version',
        'correlation_id',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'metadata' => 'array',
        'status' => PaymentStatus::class,
        'version' => 'integer',
    ];

    public function statusHistory(): HasMany
    {
        return $this->hasMany(PaymentStatusHistory::class, 'payment_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'payment_id');
    }

    /**
     * Transition the payment to a new status, enforcing the state machine and
     * optimistic locking. Must be called within a database transaction.
     *
     * @throws InvalidPaymentTransitionException if the transition is not allowed by the state machine
     * @throws PaymentConcurrencyException if the payment was modified concurrently (version mismatch)
     */
    public function transition(
        PaymentStatus $to,
        string $correlationId,
        ?string $reason = null,
        ?string $failureCode = null,
        ?string $failureReason = null,
    ): void {
        $from = $this->status;
        $expectedVersion = $this->version;

        if (! PaymentStateMachine::isAllowed($from, $to)) {
            throw new InvalidPaymentTransitionException($from, $to);
        }

        $updated = DB::table('payments')
            ->where('id', $this->id)
            ->where('version', $expectedVersion)
            ->update([
                'status' => $to->value,
                'version' => DB::raw('version + 1'),
                'failure_code' => $failureCode,
                'failure_reason' => $failureReason,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw new PaymentConcurrencyException($this->id, $expectedVersion);
        }

        $this->status = $to;
        $this->version = $expectedVersion + 1;
        $this->failure_code = $failureCode;
        $this->failure_reason = $failureReason;

        PaymentStatusHistory::create([
            'payment_id' => $this->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'correlation_id' => $correlationId,
        ]);
    }
}
