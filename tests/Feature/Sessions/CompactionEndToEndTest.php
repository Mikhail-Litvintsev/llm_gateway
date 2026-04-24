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

final class CompactionEndToEndTest extends TestCase
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
    public function compaction_block_marks_session_and_persists(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(json_encode([
                'id' => 'msg_compact',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Some reply'],
                    ['type' => 'compaction', 'summary' => 'Previous context summarized'],
                ],
                'model' => 'claude-sonnet-4-6',
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                    'cache_read_input_tokens' => 0,
                    'cache_creation_input_tokens' => 0,
                ],
            ], JSON_THROW_ON_ERROR), 200, [
                'request-id' => 'req_compact',
                'content-type' => 'application/json',
            ]),
        ]);

        $sessionId = $this->createSession();

        $response = $this->postJson("/api/v1/sessions/{$sessionId}/messages", [
            'messages' => [['role' => 'user', 'content' => 'Summarize']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $session = \DB::table('sessions')->where('session_id', $sessionId)->first();
        $this->assertNotNull($session);
        $this->assertSame(1, (int) $session->compaction_count);
        $this->assertNotNull($session->last_compaction_at);

        $assistant = \DB::table('session_messages')
            ->where('role', 'assistant')
            ->first();
        $this->assertNotNull($assistant);
        $decoded = json_decode($assistant->content, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('compaction', $decoded[1]['type']);
    }

    private function createSession(): string
    {
        $response = $this->postJson('/api/v1/sessions', [
            'model_alias' => 'claude-sonnet',
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(201);

        return $response->json('data.session_id');
    }
}
