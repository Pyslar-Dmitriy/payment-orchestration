<?php

namespace App\Application\Merchant;

use App\Domain\Merchant\ApiKey;
use App\Domain\Merchant\Merchant;

final class IssueApiKey
{
    /**
     * Generate a new API key for the merchant, persist only the hash, and return
     * the plaintext key. The plaintext is shown once — it is never stored or logged.
     *
     * @return array{id: string, key: string, key_prefix: string, created_at: string}
     */
    public function execute(Merchant $merchant, ?string $name = null): array
    {
        $plaintext = ApiKey::generatePlaintext();

        $apiKey = ApiKey::create([
            'merchant_id' => $merchant->id,
            'key_hash'    => ApiKey::hashKey($plaintext),
            'key_prefix'  => ApiKey::extractPrefix($plaintext),
            'name'        => $name,
        ]);

        return [
            'id'         => $apiKey->id,
            'key'        => $plaintext,
            'key_prefix' => $apiKey->key_prefix,
            'created_at' => $apiKey->created_at->toIso8601String(),
        ];
    }
}