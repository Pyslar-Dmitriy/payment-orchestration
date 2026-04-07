<?php

namespace App\Infrastructure\Outbox;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class OutboxEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'outbox_events';

    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'published_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
