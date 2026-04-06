<?php

namespace App\Domain\Merchant;

use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): MerchantFactory
    {
        return MerchantFactory::new();
    }

    protected $table = 'merchants';

    protected $fillable = ['name', 'email', 'status', 'callback_url'];

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }
}