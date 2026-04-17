<?php

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

class WebhookProcessingAttempt extends Model
{
    public $timestamps = false;

    protected $table = 'webhook_processing_attempts';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'raw_event_id',
        'state',
        'attempt_number',
        'error_message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
