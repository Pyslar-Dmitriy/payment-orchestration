<?php

namespace App\Domain\IdempotencyKey;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class IdempotencyKey extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'merchant_id',
        'idempotency_key',
        'status_code',
        'response_body',
    ];

    protected $casts = [
        'response_body' => 'array',
        'created_at' => 'datetime',
    ];
}
