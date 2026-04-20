<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LedgerTransaction extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'ledger_transactions';

    protected $fillable = [
        'entry_type',
        'payment_id',
        'refund_id',
        'idempotency_key',
        'correlation_id',
        'causation_id',
        'metadata',
    ];

    protected $casts = [
        'entry_type' => EntryType::class,
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'transaction_id');
    }
}
