<?php

declare(strict_types=1);

namespace Tests\Feature\Sessions;

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

final class ContextEditingEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'llm.claude.model_aliases' => [
                'claude-sonnet' => 'claude-sonnet-4-6',
                'claude-opus' => 'claude-opus-4-6',
                'claude-haiku' => 'claude-haiku-4-5',
            ],
        ]);

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
            ],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function clear_tool_uses_edit_is_sent_in_anthropic_payload(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(json_encode([
                'id' => 'msg_ctx',
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
            ], JSON_THROW_ON_ERROR), 200, [
                'request-id' => 'req_ctx',
                'content-type' => 'application/json',
            ]),
        ]);

        $createResponse = $this->postJson('/api/v1/sessions', [
            'model_alias' => 'claude-sonnet',
            'context_management' => [
                'clear_tool_uses' => new \stdClass,
            ],
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $createResponse->assertStatus(201);
        $sessionId = $createResponse->json('data.session_id');

        $response = $this->postJson("/api/v1/sessions/{$sessionId}/messages", [
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.anthropic.com/v1/messages') {
                return false;
            }
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return isset($body['context_management']['edits'][0]['type'])
                && $body['context_management']['edits'][0]['type'] === ToolTypeCatalog::EDIT_CLEAR_TOOL_USES;
        });
    }
}
