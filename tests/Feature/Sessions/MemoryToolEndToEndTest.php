<?php

declare(strict_types=1);

namespace Tests\Feature\Sessions;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Components\Claude\ToolTypeCatalog;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MemoryToolEndToEndTest extends TestCase
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
                'memory' => true,
                ToolTypeCatalog::MEMORY => true,
            ],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function memory_create_command_persists_row(): void
    {
        $toolUseResponse = json_encode([
            'id' => 'msg_mem_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_abc',
                    'name' => ToolTypeCatalog::MEMORY,
                    'input' => [
                        'command' => 'create',
                        'path' => '/memories/notes/test',
                        'file_text' => 'hello world',
                    ],
                ],
            ],
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'tool_use',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'cache_read_input_tokens' => 0,
                'cache_creation_input_tokens' => 0,
            ],
        ], JSON_THROW_ON_ERROR);

        $endTurnResponse = json_encode([
            'id' => 'msg_mem_2',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'saved']],
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 20,
                'output_tokens' => 5,
                'cache_read_input_tokens' => 0,
                'cache_creation_input_tokens' => 0,
            ],
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push($toolUseResponse, 200, ['request-id' => 'req_1', 'content-type' => 'application/json'])
                ->push($endTurnResponse, 200, ['request-id' => 'req_2', 'content-type' => 'application/json']),
        ]);

        $sessionId = $this->createSessionWithMemoryTool();

        $response = $this->postJson("/api/v1/sessions/{$sessionId}/messages", [
            'messages' => [['role' => 'user', 'content' => 'Remember this']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.stop_reason', 'end_turn');

        Http::assertSentCount(2);

        $sessionRow = \DB::table('sessions')->where('session_id', $sessionId)->first();
        $this->assertNotNull($sessionRow);

        $this->assertDatabaseHas('session_memory_files', [
            'session_id' => $sessionRow->id,
            'path' => '/memories/notes/test',
            'content' => 'hello world',
        ]);

        $this->assertDatabaseCount('session_messages', 4);
        $this->assertDatabaseHas('session_messages', ['turn_index' => 0, 'role' => 'user']);
        $this->assertDatabaseHas('session_messages', ['turn_index' => 1, 'role' => 'assistant', 'stop_reason' => 'tool_use']);
        $this->assertDatabaseHas('session_messages', ['turn_index' => 2, 'role' => 'user']);
        $this->assertDatabaseHas('session_messages', ['turn_index' => 3, 'role' => 'assistant', 'stop_reason' => 'end_turn']);
    }

    private function createSessionWithMemoryTool(): string
    {
        $response = $this->postJson('/api/v1/sessions', [
            'model_alias' => 'claude-sonnet',
            'tools' => [
                ['type' => ToolTypeCatalog::MEMORY, 'name' => 'memory'],
            ],
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(201);

        return $response->json('data.session_id');
    }
}
