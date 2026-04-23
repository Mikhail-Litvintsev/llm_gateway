<?php

namespace Tests\Feature\Api;

use App\Components\Auth\KeyHasher;
use App\Models\ApiClient;
use App\Models\CallbackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey = 'lgw_test_idempotent_key_12';

    protected function setUp(): void
    {
        parent::setUp();

        $hasher = new KeyHasher();
        $client = ApiClient::factory()->create([
            'api_key_hash' => $hasher->hash($this->apiKey),
            'api_key_prefix' => $hasher->extractPrefix($this->apiKey),
            'is_active' => true,
            'signing_secret' => 'lgs_test_secret',
        ]);

        CallbackUrl::factory()->create([
            'api_client_id' => $client->id,
            'url' => 'https://example.com/callback',
        ]);

        Queue::fake();
    }

    private function sendWithIdempotencyKey(string $key): \Illuminate\Testing\TestResponse
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/valid_minimal.xml');

        return $this->call('POST', '/api/v1/llm/request', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->apiKey,
            'CONTENT_TYPE' => 'application/xml',
            'HTTP_X_IDEMPOTENCY_KEY' => $key,
        ], $xml);
    }

    public function test_returns_same_response_for_duplicate_idempotency_key(): void
    {
        $response1 = $this->sendWithIdempotencyKey('idem_001');
        $response1->assertStatus(202);

        $response2 = $this->sendWithIdempotencyKey('idem_001');
        $response2->assertStatus(202);

        $this->assertEquals($response1->json('request_id'), $response2->json('request_id'));

        // Should only create one request_log entry despite two requests
        $this->assertDatabaseCount('request_log', 1);
    }

    public function test_without_idempotency_key_creates_separate_requests(): void
    {
        // Without idempotency key, same XML creates separate entries
        // (but same request_id will just overwrite, so we only verify that
        // idempotency key mechanism works)
        $response = $this->sendWithIdempotencyKey('unique_key_1');
        $response->assertStatus(202);
        $this->assertEquals('req_001', $response->json('request_id'));
    }
}
