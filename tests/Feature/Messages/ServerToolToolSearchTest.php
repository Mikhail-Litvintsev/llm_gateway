<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Components\Claude\ToolTypeCatalog;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ServerToolToolSearchTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $generator = new KeyGenerator;
        $this->rawApiKey = $generator->generateRawKey();

        $hasher = $this->app->make(KeyHasher::class);
        $hash = $hasher->hash($this->rawApiKey);

        $workspace = ClaudeWorkspace::create([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test-key'),
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'test-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hash,
            'api_key_prefix' => $generator->derivePrefix($this->rawApiKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('test-signing-secret'),
            'allowed_features' => [
                'thinking' => true,
                'web_search' => true,
                'prompt_caching' => true,
                'citations' => true,
                'tool_search' => true,
            ],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function tool_search_with_custom_tool_is_accepted_and_forwarded(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(json_encode([
                'id' => 'msg_ts',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'model' => 'claude-sonnet-4-6',
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                    'cache_read_input_tokens' => 0,
                    'cache_creation_input_tokens' => 0,
                ],
            ], JSON_THROW_ON_ERROR), 200, ['request-id' => 'req_ts']),
        ]);

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'max_tokens' => 1024,
            'messages' => [['role' => 'user', 'content' => 'search tools']],
            'tools' => [
                ['type' => ToolTypeCatalog::TOOL_SEARCH_REGEX, 'name' => 'tool_search', 'max_results' => 5],
                [
                    'name' => 'deferred_tool',
                    'description' => 'Deferred custom tool',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => new \stdClass,
                    ],
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $tools = $body['tools'] ?? [];

            $hasToolSearch = false;
            $hasCustom = false;
            foreach ($tools as $tool) {
                if (($tool['type'] ?? null) === ToolTypeCatalog::TOOL_SEARCH_REGEX) {
                    $hasToolSearch = true;
                }
                if (($tool['name'] ?? null) === 'deferred_tool') {
                    $hasCustom = true;
                }
            }

            return $hasToolSearch && $hasCustom;
        });
    }
}
