<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LedgerEntry extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'ledger_entries';

    protected $fillable = [
        'transaction_id',
        'account_id',
        'direction',
        'amount',
        'currency',
    ];

    protected $casts = [
        'direction' => EntryDirection::class,
        'amount' => 'integer',
        'created_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'transaction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}
