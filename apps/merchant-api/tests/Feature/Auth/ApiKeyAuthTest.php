<?php

namespace Tests\Feature\Auth;

use App\Domain\Merchant\ApiKey;
use App\Domain\Merchant\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyAuthTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Middleware: 401 on missing / invalid / expired key
    // -----------------------------------------------------------------------

    public function test_authenticated_route_rejects_request_without_authorization_header(): void
    {
        $response = $this->getJson('/api/v1/merchants/me');

        $response->assertStatus(401)
            ->assertJsonFragment(['message' => 'Missing API key.']);
    }

    public function test_authenticated_route_rejects_non_bearer_authorization_header(): void
    {
        $response = $this->getJson('/api/v1/merchants/me', [
            'Authorization' => 'Basic dXNlcjpwYXNz',
        ]);

        $response->assertStatus(401)
            ->assertJsonFragment(['message' => 'Missing API key.']);
    }

    public function test_authenticated_route_rejects_unknown_api_key(): void
    {
        $response = $this->getJson('/api/v1/merchants/me', [
            'Authorization' => 'Bearer pk_live_' . str_repeat('0', 32),
        ]);

        $response->assertStatus(401)
            ->assertJsonFragment(['message' => 'Invalid or expired API key.']);
    }

    public function test_authenticated_route_rejects_expired_api_key(): void
    {
        $plaintext = ApiKey::generatePlaintext();

        ApiKey::factory()
            ->for(Merchant::factory())
            ->expired()
            ->withPlaintext($plaintext)
            ->create();

        $response = $this->getJson('/api/v1/merchants/me', [
            'Authorization' => "Bearer {$plaintext}",
        ]);

        $response->assertStatus(401)
            ->assertJsonFragment(['message' => 'Invalid or expired API key.']);
    }

    // -----------------------------------------------------------------------
    // Middleware: merchant context binding on valid key
    // -----------------------------------------------------------------------

    public function test_valid_api_key_binds_merchant_context_to_request(): void
    {
        $plaintext = ApiKey::generatePlaintext();
        $merchant = Merchant::factory()->create();

        ApiKey::factory()
            ->for($merchant)
            ->withPlaintext($plaintext)
            ->create();

        $response = $this->getJson('/api/v1/merchants/me', [
            'Authorization' => "Bearer {$plaintext}",
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'merchant_id' => $merchant->id,
                'email'       => $merchant->email,
            ]);
    }

    public function test_valid_api_key_updates_last_used_at(): void
    {
        $plaintext = ApiKey::generatePlaintext();
        $merchant = Merchant::factory()->create();

        $apiKey = ApiKey::factory()
            ->for($merchant)
            ->withPlaintext($plaintext)
            ->create();

        $this->assertNull($apiKey->last_used_at);

        $this->getJson('/api/v1/merchants/me', [
            'Authorization' => "Bearer {$plaintext}",
        ]);

        $this->assertNotNull($apiKey->fresh()->last_used_at);
    }

    // -----------------------------------------------------------------------
    // Merchant onboarding: POST /merchants
    // -----------------------------------------------------------------------

    public function test_creating_a_merchant_returns_plaintext_api_key(): void
    {
        $response = $this->postJson('/api/v1/merchants', [
            'name'  => 'Acme Corp',
            'email' => 'admin@acme.example',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'merchant_id',
                'name',
                'email',
                'api_key' => ['id', 'key', 'key_prefix', 'created_at'],
            ]);

        $key = $response->json('api_key.key');
        $this->assertStringStartsWith('pk_live_', $key);
        $this->assertSame(40, strlen($key)); // "pk_live_" (8) + 32 hex chars
    }

    public function test_plaintext_key_is_not_stored_in_database(): void
    {
        $response = $this->postJson('/api/v1/merchants', [
            'name'  => 'Acme Corp',
            'email' => 'admin@acme.example',
        ]);

        $plaintext = $response->json('api_key.key');

        $this->assertDatabaseMissing('api_keys', ['key_hash' => $plaintext]);
        $this->assertDatabaseHas('api_keys', [
            'key_hash' => ApiKey::hashKey($plaintext),
        ]);
    }

    public function test_creating_a_merchant_with_duplicate_email_returns_422(): void
    {
        Merchant::factory()->create(['email' => 'admin@acme.example']);

        $response = $this->postJson('/api/v1/merchants', [
            'name'  => 'Acme Corp 2',
            'email' => 'admin@acme.example',
        ]);

        $response->assertStatus(422);
    }

    public function test_returned_key_authenticates_successfully(): void
    {
        $response = $this->postJson('/api/v1/merchants', [
            'name'  => 'Acme Corp',
            'email' => 'admin@acme.example',
        ]);

        $key = $response->json('api_key.key');
        $merchantId = $response->json('merchant_id');

        $meResponse = $this->getJson('/api/v1/merchants/me', [
            'Authorization' => "Bearer {$key}",
        ]);

        $meResponse->assertStatus(200)
            ->assertJsonFragment(['merchant_id' => $merchantId]);
    }

    // -----------------------------------------------------------------------
    // Key rotation: POST /api-keys/rotate
    // -----------------------------------------------------------------------

    public function test_key_rotation_returns_new_plaintext_key(): void
    {
        $plaintext = ApiKey::generatePlaintext();
        $merchant = Merchant::factory()->create();

        ApiKey::factory()
            ->for($merchant)
            ->withPlaintext($plaintext)
            ->create();

        $response = $this->postJson('/api/v1/api-keys/rotate', [], [
            'Authorization' => "Bearer {$plaintext}",
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'api_key' => ['id', 'key', 'key_prefix', 'created_at'],
            ]);

        $newKey = $response->json('api_key.key');
        $this->assertStringStartsWith('pk_live_', $newKey);
        $this->assertNotSame($plaintext, $newKey);
    }

    public function test_old_key_is_invalidated_immediately_after_rotation(): void
    {
        $plaintext = ApiKey::generatePlaintext();
        $merchant = Merchant::factory()->create();

        ApiKey::factory()
            ->for($merchant)
            ->withPlaintext($plaintext)
            ->create();

        $this->postJson('/api/v1/api-keys/rotate', [], [
            'Authorization' => "Bearer {$plaintext}",
        ]);

        // Original key must now be rejected
        $this->getJson('/api/v1/merchants/me', [
            'Authorization' => "Bearer {$plaintext}",
        ])->assertStatus(401);
    }

    public function test_new_key_works_after_rotation(): void
    {
        $plaintext = ApiKey::generatePlaintext();
        $merchant = Merchant::factory()->create();

        ApiKey::factory()
            ->for($merchant)
            ->withPlaintext($plaintext)
            ->create();

        $rotateResponse = $this->postJson('/api/v1/api-keys/rotate', [], [
            'Authorization' => "Bearer {$plaintext}",
        ]);

        $newKey = $rotateResponse->json('api_key.key');

        $this->getJson('/api/v1/merchants/me', [
            'Authorization' => "Bearer {$newKey}",
        ])->assertStatus(200)
            ->assertJsonFragment(['merchant_id' => $merchant->id]);
    }

    public function test_key_rotation_with_grace_period_keeps_old_key_valid(): void
    {
        $plaintext = ApiKey::generatePlaintext();
        $merchant = Merchant::factory()->create();

        ApiKey::factory()
            ->for($merchant)
            ->withPlaintext($plaintext)
            ->create();

        $this->postJson('/api/v1/api-keys/rotate', ['grace_minutes' => 60], [
            'Authorization' => "Bearer {$plaintext}",
        ])->assertStatus(201);

        // Old key should still work within the grace window
        $this->getJson('/api/v1/merchants/me', [
            'Authorization' => "Bearer {$plaintext}",
        ])->assertStatus(200);
    }

    public function test_rotation_requires_authentication(): void
    {
        $this->postJson('/api/v1/api-keys/rotate')
            ->assertStatus(401);
    }
}