<?php

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

class WebhookDedup extends Model
{
    public $timestamps = false;

    protected $table = 'webhook_dedup';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'provider',
        'event_id',
        'raw_event_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
