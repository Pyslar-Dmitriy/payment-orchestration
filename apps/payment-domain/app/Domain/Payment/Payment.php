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
        'customer_reference',
        'payment_method_reference',
        'amount',
        'currency',
        'status',
        'correlation_id',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'metadata' => 'array',
    ];

    public function statusHistory(): HasMany
    {
        return $this->hasMany(PaymentStatusHistory::class, 'payment_id');
    }
}
