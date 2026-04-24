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

final class PauseTurnAutoResumeTest extends TestCase
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

    #[Test]
    public function auto_resume_true_loops_until_end_turn(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->messageBody('msg_1', 'pause_turn', 'pausing...'), 200, $this->anthropicHeaders('req_1'))
                ->push($this->messageBody('msg_2', 'pause_turn', 'still pausing'), 200, $this->anthropicHeaders('req_2'))
                ->push($this->messageBody('msg_3', 'end_turn', 'done'), 200, $this->anthropicHeaders('req_3')),
        ]);

        $sessionId = $this->createSession(autoResume: true);

        $response = $this->postJson("/api/v1/sessions/{$sessionId}/messages", [
            'messages' => [['role' => 'user', 'content' => 'Start a long task']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.stop_reason', 'end_turn');
        $response->assertJsonPath('data.content.0.text', 'done');

        Http::assertSentCount(3);

        $this->assertDatabaseCount('session_messages', 4);
        $this->assertDatabaseHas('session_messages', [
            'turn_index' => 0,
            'role' => 'user',
        ]);
        $this->assertDatabaseHas('session_messages', [
            'turn_index' => 1,
            'role' => 'assistant',
            'stop_reason' => 'pause_turn',
        ]);
        $this->assertDatabaseHas('session_messages', [
            'turn_index' => 2,
            'role' => 'assistant',
            'stop_reason' => 'pause_turn',
        ]);
        $this->assertDatabaseHas('session_messages', [
            'turn_index' => 3,
            'role' => 'assistant',
            'stop_reason' => 'end_turn',
        ]);
    }

    #[Test]
    public function auto_resume_false_returns_pause_turn_to_client(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push($this->messageBody('msg_1', 'pause_turn', 'paused'), 200, $this->anthropicHeaders('req_1')),
        ]);

        $sessionId = $this->createSession(autoResume: false);

        $response = $this->postJson("/api/v1/sessions/{$sessionId}/messages", [
            'messages' => [['role' => 'user', 'content' => 'Start a long task']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.stop_reason', 'pause_turn');
        $response->assertJsonPath('data.content.0.text', 'paused');

        Http::assertSentCount(1);
        $this->assertCount(1, Http::recorded());

        $this->assertDatabaseCount('session_messages', 2);
        $this->assertDatabaseHas('session_messages', [
            'turn_index' => 0,
            'role' => 'user',
        ]);
        $this->assertDatabaseHas('session_messages', [
            'turn_index' => 1,
            'role' => 'assistant',
            'stop_reason' => 'pause_turn',
        ]);
    }

    private function createSession(bool $autoResume): string
    {
        $response = $this->postJson('/api/v1/sessions', [
            'model_alias' => 'claude-sonnet',
            'auto_resume' => $autoResume,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(201);

        return $response->json('data.session_id');
    }

    private function messageBody(string $id, string $stopReason, string $text): string
    {
        return json_encode([
            'id' => $id,
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => $text]],
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => $stopReason,
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'cache_read_input_tokens' => 0,
                'cache_creation_input_tokens' => 0,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function anthropicHeaders(string $requestId): array
    {
        return [
            'request-id' => $requestId,
            'content-type' => 'application/json',
        ];
    }
}
