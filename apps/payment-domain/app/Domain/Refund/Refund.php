<?php

namespace App\Domain\Refund;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $casts = [
        'amount' => 'integer',
        'status' => RefundStatus::class,
    ];
}
