<?php

namespace App\Domain\Payment;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        // TODO TASK-055: version will be checked before state writes (optimistic locking)
    ];

    public function statusHistory(): HasMany
    {
        return $this->hasMany(PaymentStatusHistory::class, 'payment_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'payment_id');
    }
}
