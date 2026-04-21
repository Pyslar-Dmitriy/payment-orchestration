<?php

declare(strict_types=1);

namespace App\Domain\Callback;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CallbackDelivery extends Model
{
    use HasUuids;

    protected $table = 'merchant_callback_deliveries';

    protected $fillable = [
        'subscription_id',
        'payment_id',
        'merchant_id',
        'event_type',
        'payload',
        'endpoint_url',
        'status',
        'attempt_count',
        'last_attempted_at',
        'next_attempt_at',
        'delivered_at',
        'correlation_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => DeliveryStatus::class,
        'last_attempted_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CallbackSubscription::class, 'subscription_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CallbackAttempt::class, 'delivery_id')->orderBy('attempt_number');
    }
}
