<?php

namespace Tests\Feature\Api;

use App\Components\Auth\KeyHasher;
use App\Models\ApiClient;
use App\Models\CallbackUrl;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_request_without_authorization(): void
    {
        $response = $this->postJson('/api/v1/llm/request');

        $response->assertStatus(401);
        $response->assertJson(['status' => 'error', 'error' => ['code' => 'UNAUTHORIZED']]);
    }

    public function test_rejects_invalid_api_key(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid_key_12345678',
        ])->postJson('/api/v1/llm/request');

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'error' => ['code' => 'FORBIDDEN']]);
    }

    public function test_rejects_inactive_client(): void
    {
        $apiKey = 'lgw_test_inactive_key_12345';
        $hasher = new KeyHasher();

        ApiClient::factory()->create([
            'api_key_hash' => $hasher->hash($apiKey),
            'api_key_prefix' => $hasher->extractPrefix($apiKey),
            'is_active' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->postJson('/api/v1/llm/request');

        $response->assertStatus(403);
    }

    public function test_accepts_valid_api_key(): void
    {
        $apiKey = 'lgw_test_valid_key_12345678';
        $hasher = new KeyHasher();

        $client = ApiClient::factory()->create([
            'api_key_hash' => $hasher->hash($apiKey),
            'api_key_prefix' => $hasher->extractPrefix($apiKey),
            'is_active' => true,
        ]);

        CallbackUrl::factory()->create([
            'api_client_id' => $client->id,
            'url' => 'https://example.com/callback',
        ]);

        // Should pass auth, but may fail on content type
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->postJson('/api/v1/llm/request');

        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
    }

    public function test_rejects_malformed_authorization_header(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Basic some_token',
        ])->postJson('/api/v1/llm/request');

        $response->assertStatus(401);
    }
}
