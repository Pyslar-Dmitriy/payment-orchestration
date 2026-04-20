<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LedgerAccount extends Model
{
    use HasUuids;

    protected $table = 'ledger_accounts';

    protected $fillable = [
        'type',
        'owner_id',
        'currency',
    ];

    protected $casts = [
        'type' => AccountType::class,
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'account_id');
    }

    public function balance(): int
    {
        return (int) $this->entries()
            ->selectRaw(
                "SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) AS balance"
            )
            ->value('balance');
    }
}
