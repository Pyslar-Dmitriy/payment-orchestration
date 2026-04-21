<?php

declare(strict_types=1);

namespace App\Domain\Callback;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CallbackAttempt extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'merchant_callback_attempts';

    protected $fillable = [
        'delivery_id',
        'attempt_number',
        'attempted_at',
        'http_status_code',
        'response_body',
        'response_headers',
        'failure_reason',
        'duration_ms',
    ];

    protected $casts = [
        'response_headers' => 'array',
        'failure_reason' => FailureReason::class,
        'attempted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(CallbackDelivery::class, 'delivery_id');
    }
}
