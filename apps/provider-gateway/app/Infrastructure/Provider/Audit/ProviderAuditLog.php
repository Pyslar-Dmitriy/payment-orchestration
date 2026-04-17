<?php

declare(strict_types=1);

namespace App\Infrastructure\Provider\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of a single provider adapter call.
 *
 * No updated_at — rows are never modified after insertion.
 */
final class ProviderAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'provider_audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'provider_key',
        'operation',
        'payment_uuid',
        'refund_uuid',
        'correlation_id',
        'request_payload',
        'response_payload',
        'outcome',
        'error_code',
        'error_message',
        'duration_ms',
        'requested_at',
        'responded_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
