<?php

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

class WebhookEventRaw extends Model
{
    public $timestamps = false;

    protected $table = 'webhook_events_raw';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'provider',
        'event_id',
        'headers',
        'payload',
        'signature_verified',
        'correlation_id',
        'processing_state',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'signature_verified' => 'boolean',
            'received_at' => 'datetime',
        ];
    }
}
