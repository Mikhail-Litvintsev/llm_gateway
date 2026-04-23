<?php

namespace Tests\Feature\Pipeline;

use App\Components\Auth\KeyHasher;
use App\Components\RequestPipeline\Enums\RequestStatus;
use App\Models\ApiClient;
use App\Models\CallbackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FallbackPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey = 'lgw_test_fallback_key_1234';

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
            'llm.providers.openai' => [
                'endpoint' => 'https://api.openai.com/v1/chat/completions',
                'api_key' => 'test-key',
                'default_model' => 'gpt-4o',
            ],
        ]);
    }

    public function test_falls_back_to_secondary_provider_on_failure(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                ['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
                503,
            ),
            'api.openai.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../../Fixtures/responses/openai_success.json'), true),
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
            'status' => RequestStatus::Completed->value,
        ]);

        // Should have raw responses from both providers
        $this->assertDatabaseCount('raw_responses', 2);
    }
}
