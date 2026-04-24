<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class DailyAggregate extends Model
{
    protected $table = 'daily_aggregates';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'date',
        'currency',
        'payments_initiated',
        'payments_captured',
        'payments_failed',
        'payments_cancelled',
        'volume_initiated_cents',
        'volume_captured_cents',
        'refunds_succeeded',
        'refund_volume_cents',
        'updated_at',
    ];
}
