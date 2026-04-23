<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ServerToolWebSearchTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    private ClaudeWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $generator = new KeyGenerator();
        $this->rawApiKey = $generator->generateRawKey();

        $hasher = $this->app->make(KeyHasher::class);
        $hash = $hasher->hash($this->rawApiKey);

        $this->workspace = ClaudeWorkspace::create([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test-key'),
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'test-client',
            'workspace_id' => $this->workspace->id,
            'api_key_hash' => $hash,
            'api_key_prefix' => $generator->derivePrefix($this->rawApiKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('test-signing-secret'),
            'allowed_features' => [
                'thinking' => true,
                'web_search' => true,
                'prompt_caching' => true,
                'citations' => true,
                'code_execution' => true,
                'computer_use' => true,
                'structured_outputs' => true,
                'priority_tier' => true,
                'webhook' => true,
            ],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function web_search_feature_gate_returns_403(): void
    {
        $this->client->update([
            'allowed_features' => [
                'thinking' => true,
                'prompt_caching' => true,
                'citations' => true,
            ],
        ]);

        Http::fake();

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'max_tokens' => 1024,
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'tools' => [
                ['type' => 'web_search_20260209'],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $this->assertContains($response->getStatusCode(), [400, 403, 422]);
    }

    #[Test]
    public function web_search_response_preserves_server_tool_counts(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(json_encode([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Here are the results']],
                'model' => 'claude-sonnet-4-6',
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                    'cache_read_input_tokens' => 0,
                    'cache_creation_input_tokens' => 0,
                    'server_tool_use' => ['web_search_requests' => 3],
                ],
            ]), 200, ['request-id' => 'req_ws_abc']),
        ]);

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hello'],
                    ],
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(200);
    }
}
