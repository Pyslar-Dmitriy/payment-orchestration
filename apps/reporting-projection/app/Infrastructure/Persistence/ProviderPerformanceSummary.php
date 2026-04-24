<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class ProviderPerformanceSummary extends Model
{
    protected $table = 'provider_performance_summaries';

    protected $primaryKey = 'provider_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'provider_id',
        'total_attempts',
        'authorized_count',
        'captured_count',
        'failed_count',
        'updated_at',
    ];
}
