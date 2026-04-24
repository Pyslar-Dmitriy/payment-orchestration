<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class MerchantPaymentSummary extends Model
{
    protected $table = 'merchant_payment_summaries';

    protected $primaryKey = 'merchant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'merchant_id',
        'total_count',
        'captured_count',
        'failed_count',
        'refunded_count',
        'cancelled_count',
        'total_volume_cents',
        'captured_volume_cents',
        'refunded_volume_cents',
        'updated_at',
    ];
}
