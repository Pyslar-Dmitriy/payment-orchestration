<?php

namespace App\Domain\Payment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'payment_status_history';

    protected $fillable = [
        'payment_id',
        'from_status',
        'to_status',
        'reason',
        'correlation_id',
        'causation_id',
    ];

    protected $casts = [
        'from_status' => PaymentStatus::class,
        'to_status' => PaymentStatus::class,
        'created_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
