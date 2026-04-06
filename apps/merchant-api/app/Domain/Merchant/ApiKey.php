<?php

namespace App\Domain\Merchant;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): ApiKeyFactory
    {
        return ApiKeyFactory::new();
    }

    protected $table = 'api_keys';

    protected $fillable = ['merchant_id', 'key_hash', 'key_prefix', 'name', 'last_used_at', 'expires_at'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Generate a new plaintext API key. Never persisted — returned to the merchant once.
     * Format: pk_live_<32-char-hex> (128 bits of entropy).
     */
    public static function generatePlaintext(): string
    {
        return 'pk_live_' . bin2hex(random_bytes(16));
    }

    /**
     * Deterministic SHA-256 hash of the plaintext key for storage and O(1) lookup.
     * The key's 128-bit random suffix makes brute-force infeasible.
     */
    public static function hashKey(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Returns the first 8 characters of the key (e.g. "pk_live_").
     * Safe to log; the random suffix must never appear in logs.
     */
    public static function extractPrefix(string $plaintext): string
    {
        return substr($plaintext, 0, 8);
    }

    /**
     * Find an active (non-expired) key by plaintext bearer token, or return null.
     */
    public static function findByPlaintext(string $plaintext): ?self
    {
        $hash = self::hashKey($plaintext);

        return self::where('key_hash', $hash)
            ->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }
}