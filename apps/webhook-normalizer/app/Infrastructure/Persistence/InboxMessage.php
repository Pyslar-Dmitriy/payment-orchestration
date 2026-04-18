<?php

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

class InboxMessage extends Model
{
    public $timestamps = false;

    protected $table = 'inbox_messages';

    protected $primaryKey = 'message_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'message_id',
        'processed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
