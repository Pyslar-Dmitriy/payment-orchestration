<?php

namespace App\Domain\Refund;

use App\Domain\Refund\Exceptions\InvalidRefundTransitionException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class Refund extends Model
{
    use HasUlids;

    protected $table = 'refunds';

    protected $fillable = [
        'payment_id',
        'merchant_id',
        'amount',
        'currency',
        'status',
        'correlation_id',
        'failure_reason',
    ];

    protected $casts = [
        'amount' => 'integer',
        'status' => RefundStatus::class,
    ];

    /**
     * Transition the refund to a new status, enforcing the state machine.
     * Must be called within a database transaction.
     *
     * @throws InvalidRefundTransitionException if the transition is not allowed by the state machine
     */
    public function transition(
        RefundStatus $to,
        string $correlationId,
        ?string $failureReason = null,
    ): void {
        $from = $this->status;

        if (! RefundStateMachine::isAllowed($from, $to)) {
            throw new InvalidRefundTransitionException($from, $to);
        }

        DB::table('refunds')
            ->where('id', $this->id)
            ->update([
                'status' => $to->value,
                'failure_reason' => $failureReason,
                'updated_at' => now(),
            ]);

        $this->status = $to;
        $this->failure_reason = $failureReason;
    }
}
