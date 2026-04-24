<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class PaymentProjection extends Model
{
    protected $table = 'payment_projections';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'merchant_id',
        'external_reference',
        'amount',
        'currency',
        'status',
        'provider_id',
        'provider_transaction_id',
        'authorized_at',
        'captured_at',
        'refunded_at',
        'failed_at',
    ];

    protected $casts = [
        'authorized_at' => 'datetime',
        'captured_at' => 'datetime',
        'refunded_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
