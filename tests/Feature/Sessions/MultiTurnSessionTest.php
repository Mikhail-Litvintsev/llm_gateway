<?php

declare(strict_types=1);

namespace Tests\Feature\Sessions;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MultiTurnSessionTest extends TestCase
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

        $generator = new KeyGenerator();
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
                'code_execution' => true,
                'computer_use' => true,
                'structured_outputs' => true,
                'priority_tier' => true,
                'webhook' => true,
                'memory' => true,
            ],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    private function fakeAnthropicResponse(string $text = 'Hello!'): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(json_encode([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => $text]],
                'model' => 'claude-sonnet-4-6',
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                    'cache_read_input_tokens' => 0,
                    'cache_creation_input_tokens' => 0,
                ],
            ], JSON_THROW_ON_ERROR), 200, [
                'request-id' => 'req_test_abc',
                'content-type' => 'application/json',
            ]),
        ]);
    }

    private function createSession(): string
    {
        $response = $this->postJson('/api/v1/sessions', [
            'model_alias' => 'claude-sonnet',
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(201);

        return $response->json('data.session_id');
    }

    #[Test]
    public function multi_turn_persists_messages_in_order(): void
    {
        $this->fakeAnthropicResponse('First reply');
        $sessionId = $this->createSession();

        $firstResponse = $this->postJson("/api/v1/sessions/{$sessionId}/messages", [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $firstResponse->assertStatus(200);

        $this->assertDatabaseCount('session_messages', 2);
        $this->assertDatabaseHas('session_messages', [
            'turn_index' => 0,
            'role' => 'user',
        ]);
        $this->assertDatabaseHas('session_messages', [
            'turn_index' => 1,
            'role' => 'assistant',
        ]);

        $this->fakeAnthropicResponse('Second reply');

        $secondResponse = $this->postJson("/api/v1/sessions/{$sessionId}/messages", [
            'messages' => [['role' => 'user', 'content' => 'Follow up']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $secondResponse->assertStatus(200);

        $this->assertDatabaseCount('session_messages', 4);
    }

    #[Test]
    public function model_alias_cannot_be_changed_by_send_body(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $sessionId = $this->createSession();

        $response = $this->postJson("/api/v1/sessions/{$sessionId}/messages", [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'model_alias' => 'claude-opus',
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function soft_deleted_session_returns_404_on_send(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $sessionId = $this->createSession();

        $this->deleteJson("/api/v1/sessions/{$sessionId}", [], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ])->assertStatus(204);

        $this->fakeAnthropicResponse();

        $response = $this->postJson("/api/v1/sessions/{$sessionId}/messages", [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(404);
    }
}
