<?php

namespace Tests\Feature\Api;

use App\Components\Auth\KeyHasher;
use App\Models\ApiClient;
use App\Models\CallbackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey = 'lgw_test_ratelimit_key_123';

    protected function setUp(): void
    {
        parent::setUp();

        $hasher = new KeyHasher();
        $client = ApiClient::factory()->create([
            'api_key_hash' => $hasher->hash($this->apiKey),
            'api_key_prefix' => $hasher->extractPrefix($this->apiKey),
            'is_active' => true,
            'rate_limit' => 3,
            'signing_secret' => 'lgs_test_secret',
        ]);

        CallbackUrl::factory()->create([
            'api_client_id' => $client->id,
            'url' => 'https://example.com/callback',
        ]);

        Queue::fake();
    }

    private function sendValidRequest(): \Illuminate\Testing\TestResponse
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/valid_minimal.xml');

        return $this->call('POST', '/api/v1/llm/request', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->apiKey,
            'CONTENT_TYPE' => 'application/xml',
        ], $xml);
    }

    public function test_returns_rate_limit_headers(): void
    {
        $response = $this->sendValidRequest();

        $response->assertHeader('X-RateLimit-Limit', '3');
        $response->assertHeader('X-RateLimit-Remaining');
        $response->assertHeader('X-RateLimit-Reset');
    }

    public function test_blocks_when_rate_limit_exceeded(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->sendValidRequest();
        }

        $response = $this->sendValidRequest();

        $response->assertStatus(429);
        $response->assertJson(['status' => 'error', 'error' => ['code' => 'RATE_LIMIT_EXCEEDED']]);
        $response->assertHeader('Retry-After');
    }
}
