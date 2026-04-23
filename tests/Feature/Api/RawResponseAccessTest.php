<?php

namespace Tests\Feature\Api;

use App\Components\Auth\KeyHasher;
use App\Models\ApiClient;
use App\Models\RawResponse;
use App\Models\RequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RawResponseAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey = 'lgw_test_raw_response_key_123';
    private ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $hasher = new KeyHasher();
        $this->client = ApiClient::factory()->create([
            'api_key_hash' => $hasher->hash($this->apiKey),
            'api_key_prefix' => $hasher->extractPrefix($this->apiKey),
            'is_active' => true,
            'signing_secret' => 'lgs_test_secret',
        ]);
    }

    private function getRawResponses(string $requestId, ?string $bearerToken = null): \Illuminate\Testing\TestResponse
    {
        $headers = [];
        if ($bearerToken !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerToken;
        }

        return $this->call('GET', "/api/v1/llm/requests/{$requestId}/raw-responses", [], [], [], $headers);
    }

    public function test_returns_401_without_authorization_header(): void
    {
        $response = $this->getJson('/api/v1/llm/requests/req_001/raw-responses');

        $response->assertStatus(401);
        $response->assertJson(['status' => 'error', 'error' => ['code' => 'UNAUTHORIZED']]);
    }

    public function test_returns_403_with_invalid_api_key(): void
    {
        $response = $this->getRawResponses('req_001', 'lgw_invalid_key_999');

        $response->assertStatus(403);
        $response->assertJson(['status' => 'error', 'error' => ['code' => 'FORBIDDEN']]);
    }

    public function test_returns_404_for_nonexistent_request_id(): void
    {
        $response = $this->getRawResponses('req_nonexistent', $this->apiKey);

        $response->assertStatus(404);
        $response->assertJson(['status' => 'error', 'error' => ['code' => 'REQUEST_NOT_FOUND']]);
    }

    public function test_returns_404_for_another_clients_request(): void
    {
        $otherClient = ApiClient::factory()->create();
        $requestLog = RequestLog::factory()->create([
            'api_client_id' => $otherClient->id,
            'request_id' => 'req_other_client',
        ]);

        RawResponse::create([
            'request_log_id' => $requestLog->id,
            'provider' => 'claude',
            'model' => 'claude-sonnet-4-6',
            'http_status' => 200,
            'response_body' => ['content' => 'secret'],
            'is_fallback_attempt' => false,
            'created_at' => now(),
        ]);

        $response = $this->getRawResponses('req_other_client', $this->apiKey);

        $response->assertStatus(404);
        $response->assertJson(['status' => 'error', 'error' => ['code' => 'REQUEST_NOT_FOUND']]);
    }

    public function test_returns_200_with_empty_data_when_no_raw_responses(): void
    {
        RequestLog::factory()->create([
            'api_client_id' => $this->client->id,
            'request_id' => 'req_no_raw',
        ]);

        $response = $this->getRawResponses('req_no_raw', $this->apiKey);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'ok',
            'request_id' => 'req_no_raw',
            'data' => [],
        ]);
    }

    public function test_returns_200_with_raw_responses(): void
    {
        $requestLog = RequestLog::factory()->create([
            'api_client_id' => $this->client->id,
            'request_id' => 'req_with_raw',
        ]);

        RawResponse::create([
            'request_log_id' => $requestLog->id,
            'provider' => 'claude',
            'model' => 'claude-sonnet-4-6',
            'http_status' => 200,
            'response_body' => ['content' => [['type' => 'text', 'text' => 'Hello']]],
            'response_headers' => ['content-type' => 'application/json'],
            'is_fallback_attempt' => false,
            'duration_ms' => 1230,
            'created_at' => now(),
        ]);

        $response = $this->getRawResponses('req_with_raw', $this->apiKey);

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertEquals('ok', $json['status']);
        $this->assertEquals('req_with_raw', $json['request_id']);
        $this->assertCount(1, $json['data']);

        $item = $json['data'][0];
        $this->assertEquals('claude', $item['provider']);
        $this->assertEquals('claude-sonnet-4-6', $item['model']);
        $this->assertEquals(200, $item['http_status']);
        $this->assertFalse($item['is_fallback_attempt']);
        $this->assertEquals(1230, $item['duration_ms']);
        $this->assertArrayHasKey('response_body', $item);
        $this->assertArrayHasKey('response_headers', $item);
        $this->assertArrayHasKey('created_at', $item);
    }

    public function test_returns_multiple_raw_responses_with_fallback_in_order(): void
    {
        $requestLog = RequestLog::factory()->create([
            'api_client_id' => $this->client->id,
            'request_id' => 'req_fallback',
        ]);

        RawResponse::create([
            'request_log_id' => $requestLog->id,
            'provider' => 'claude',
            'model' => 'claude-sonnet-4-6',
            'http_status' => 500,
            'response_body' => ['error' => 'internal'],
            'is_fallback_attempt' => false,
            'duration_ms' => 500,
            'created_at' => now()->subSeconds(2),
        ]);

        RawResponse::create([
            'request_log_id' => $requestLog->id,
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'http_status' => 200,
            'response_body' => ['choices' => []],
            'is_fallback_attempt' => true,
            'duration_ms' => 980,
            'created_at' => now(),
        ]);

        $response = $this->getRawResponses('req_fallback', $this->apiKey);

        $response->assertStatus(200);
        $json = $response->json();

        $this->assertCount(2, $json['data']);
        $this->assertEquals('claude', $json['data'][0]['provider']);
        $this->assertFalse($json['data'][0]['is_fallback_attempt']);
        $this->assertEquals('openai', $json['data'][1]['provider']);
        $this->assertTrue($json['data'][1]['is_fallback_attempt']);
    }

    public function test_returns_404_for_invalid_request_id_format(): void
    {
        $response = $this->getRawResponses('req<script>alert(1)</script>', $this->apiKey);

        $response->assertStatus(404);
    }

    public function test_filters_sensitive_headers_from_response(): void
    {
        $requestLog = RequestLog::factory()->create([
            'api_client_id' => $this->client->id,
            'request_id' => 'req_sensitive',
        ]);

        RawResponse::create([
            'request_log_id' => $requestLog->id,
            'provider' => 'claude',
            'model' => 'claude-sonnet-4-6',
            'http_status' => 200,
            'response_body' => ['content' => 'test'],
            'response_headers' => [
                'content-type' => 'application/json',
                'Authorization' => 'Bearer sk-secret-key',
                'x-api-key' => 'secret-api-key',
                'Api-Key' => 'another-secret',
                'x-request-id' => 'safe-value',
            ],
            'is_fallback_attempt' => false,
            'created_at' => now(),
        ]);

        $response = $this->getRawResponses('req_sensitive', $this->apiKey);

        $response->assertStatus(200);
        $headers = $response->json('data.0.response_headers');

        $this->assertArrayHasKey('content-type', $headers);
        $this->assertArrayHasKey('x-request-id', $headers);
        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertArrayNotHasKey('x-api-key', $headers);
        $this->assertArrayNotHasKey('Api-Key', $headers);
    }

    public function test_returns_null_response_headers_when_stored_as_null(): void
    {
        $requestLog = RequestLog::factory()->create([
            'api_client_id' => $this->client->id,
            'request_id' => 'req_null_headers',
        ]);

        RawResponse::create([
            'request_log_id' => $requestLog->id,
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'http_status' => 200,
            'response_body' => ['choices' => []],
            'response_headers' => null,
            'is_fallback_attempt' => false,
            'created_at' => now(),
        ]);

        $response = $this->getRawResponses('req_null_headers', $this->apiKey);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.0.response_headers'));
    }
}
