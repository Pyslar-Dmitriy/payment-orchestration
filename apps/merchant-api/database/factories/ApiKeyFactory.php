<?php

namespace Database\Factories;

use App\Domain\Merchant\ApiKey;
use App\Domain\Merchant\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition(): array
    {
        $plaintext = ApiKey::generatePlaintext();

        return [
            'merchant_id' => Merchant::factory(),
            'key_hash' => ApiKey::hashKey($plaintext),
            'key_prefix' => ApiKey::extractPrefix($plaintext),
            'name' => null,
            'last_used_at' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Create a key with a known plaintext for test assertions.
     * Usage: ApiKeyFactory::new()->withPlaintext($key)
     */
    public function withPlaintext(string $plaintext): static
    {
        return $this->state([
            'key_hash' => ApiKey::hashKey($plaintext),
            'key_prefix' => ApiKey::extractPrefix($plaintext),
        ]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
