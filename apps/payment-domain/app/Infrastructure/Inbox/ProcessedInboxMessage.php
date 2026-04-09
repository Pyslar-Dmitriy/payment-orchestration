<?php

namespace App\Infrastructure\Inbox;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ProcessedInboxMessage extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'processed_inbox_messages';

    protected $fillable = [
        'message_id',
        'message_type',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
