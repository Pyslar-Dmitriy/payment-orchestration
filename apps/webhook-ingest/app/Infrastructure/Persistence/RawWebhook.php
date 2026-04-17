<?php

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

class RawWebhook extends Model
{
    public $timestamps = false;

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
        'enqueued_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'signature_verified' => 'boolean',
            'enqueued_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
