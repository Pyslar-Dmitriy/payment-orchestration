<?php

namespace App\Domain\Payment;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentAttempt extends Model
{
    use HasUlids;

    protected $table = 'payment_attempts';

    protected $fillable = [
        'payment_id',
        'attempt_number',
        'provider_id',
        'provider_transaction_id',
        'status',
        'failure_code',
        'failure_reason',
        'provider_response',
        'correlation_id',
    ];

    protected $casts = [
        'status' => PaymentAttemptStatus::class,
        'provider_response' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
