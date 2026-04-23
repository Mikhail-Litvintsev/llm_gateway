<?php

namespace Tests\Feature\Pipeline;

use App\Components\Auth\KeyHasher;
use App\Components\RequestPipeline\Enums\RequestStatus;
use App\Models\ApiClient;
use App\Models\CallbackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FullPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey = 'lgw_test_pipeline_key_1234';

    protected function setUp(): void
    {
        parent::setUp();

        $hasher = new KeyHasher();
        $client = ApiClient::factory()->create([
            'api_key_hash' => $hasher->hash($this->apiKey),
            'api_key_prefix' => $hasher->extractPrefix($this->apiKey),
            'is_active' => true,
            'signing_secret' => 'lgs_test_secret',
            'dev_mode' => false,
        ]);

        CallbackUrl::factory()->create([
            'api_client_id' => $client->id,
            'url' => 'https://example.com/callback',
        ]);

        config([
            'llm.providers.claude' => [
                'endpoint' => 'https://api.anthropic.com/v1/messages',
                'api_key' => 'test-key',
                'default_model' => 'claude-sonnet-4-20250514',
                'default_max_tokens' => 4096,
            ],
        ]);
    }

    public function test_end_to_end_accept_process_callback(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../../Fixtures/responses/claude_success.json'), true),
                200,
            ),
            'example.com/callback' => Http::response('', 200),
        ]);

        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/valid_minimal.xml');

        // 1. Accept request (sync queue processes jobs immediately)
        $response = $this->call('POST', '/api/v1/llm/request', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->apiKey,
            'CONTENT_TYPE' => 'application/xml',
        ], $xml);

        $response->assertStatus(202);

        // With sync queue, the job should have been processed
        $this->assertDatabaseHas('request_log', [
            'request_id' => 'req_001',
            'status' => RequestStatus::Completed->value,
        ]);

        // Response log should exist
        $this->assertDatabaseHas('response_log', [
            'status' => 'ok',
        ]);

        // Raw response saved
        $this->assertDatabaseHas('raw_responses', [
            'provider' => 'claude',
            'http_status' => 200,
        ]);

        // Callback should have been sent
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'example.com/callback');
        });
    }

    public function test_end_to_end_with_full_request(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../../Fixtures/responses/claude_success.json'), true),
                200,
            ),
            'example.com/callback' => Http::response('', 200),
        ]);

        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/valid_full.xml');

        $response = $this->call('POST', '/api/v1/llm/request', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->apiKey,
            'CONTENT_TYPE' => 'application/xml',
        ], $xml);

        $response->assertStatus(202);

        $this->assertDatabaseHas('request_log', [
            'request_id' => 'req_full_001',
            'provider_requested' => 'claude',
            'model_requested' => 'claude-sonnet-4-20250514',
            'has_tools' => true,
        ]);
    }
}
