<?php

declare(strict_types=1);

namespace App\Domain\Callback;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CallbackSubscription extends Model
{
    use HasUuids;

    protected $table = 'merchant_callback_subscriptions';

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'merchant_id',
        'callback_url',
        'signing_secret',
        'signing_algorithm',
        'event_types',
        'is_active',
    ];

    protected $hidden = [
        'signing_secret',
    ];

    protected $casts = [
        'event_types' => 'array',
        'is_active' => 'boolean',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(CallbackDelivery::class, 'subscription_id');
    }
}
