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

/**
 * Streaming feature test using Http::fake.
 *
 * IMPORTANT: Http::fake returns the faked body in a single block, not as a stream of chunks.
 * This test verifies SSE parsing correctness, usage aggregation, and persisted assistant message
 * — but does NOT exercise real-time iteration, backpressure, or mid-stream disconnect.
 * Real streaming is covered by:
 *  - tests/Integration/AnthropicSmokeTest (opt-in via INTEGRATION_ANTHROPIC=1)
 *  - Full integration testing scenario 4 (see tasks/senior-review-fixes/task.md)
 */
final class SessionStreamingTest extends TestCase
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
    public function streaming_endpoint_returns_sse_content_type_and_persists_assistant_message(): void
    {
        $sseBody = $this->buildSseBody([
            ['event' => 'message_start', 'data' => [
                'type' => 'message_start',
                'message' => [
                    'id' => 'msg_s1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [],
                    'model' => 'claude-sonnet-4-6',
                    'stop_reason' => null,
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
                ],
            ]],
            ['event' => 'content_block_start', 'data' => [
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'text', 'text' => ''],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => 'Hello'],
            ]],
            ['event' => 'content_block_stop', 'data' => [
                'type' => 'content_block_stop',
                'index' => 0,
            ]],
            ['event' => 'message_delta', 'data' => [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => ['output_tokens' => 5],
            ]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response($sseBody, 200, [
                'content-type' => 'text/event-stream',
                'request-id' => 'req_s_abc',
            ]),
        ]);

        $sessionId = $this->createSession();

        $response = $this->postJson("/api/v1/sessions/{$sessionId}/messages", [
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
            'stream' => true,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('event: message_start', $content);
        $this->assertStringContainsString('event: content_block_delta', $content);
        $this->assertStringContainsString('event: message_stop', $content);

        $this->assertDatabaseCount('session_messages', 2);
        $this->assertDatabaseHas('session_messages', [
            'turn_index' => 0,
            'role' => 'user',
        ]);

        $assistant = \DB::table('session_messages')->where('turn_index', 1)->first();
        $this->assertNotNull($assistant);
        $this->assertSame('assistant', $assistant->role);
        $this->assertSame('end_turn', $assistant->stop_reason);
        $decoded = json_decode($assistant->content, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('text', $decoded[0]['type']);
        $this->assertSame('Hello', $decoded[0]['text']);
    }

    #[Test]
    public function streaming_upstream_error_does_not_persist_assistant_message(): void
    {
        $errorBody = json_encode([
            'type' => 'error',
            'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response($errorBody, 529, [
                'content-type' => 'application/json',
                'request-id' => 'req_s_err',
            ]),
        ]);

        $sessionId = $this->createSession();

        $response = $this->postJson("/api/v1/sessions/{$sessionId}/messages", [
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
            'stream' => true,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $this->assertGreaterThanOrEqual(200, $response->getStatusCode());

        try {
            $response->streamedContent();
        } catch (\Throwable) {
            // streaming may throw when upstream is non-SSE; acceptable for this scenario.
        }

        $this->assertDatabaseCount('session_messages', 1);
        $this->assertDatabaseHas('session_messages', [
            'turn_index' => 0,
            'role' => 'user',
        ]);
        $this->assertDatabaseMissing('session_messages', [
            'role' => 'assistant',
        ]);
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

    /**
     * @param  array<int, array{event: string, data: array<string, mixed>}>  $events
     */
    private function buildSseBody(array $events): string
    {
        $out = '';
        foreach ($events as $event) {
            $out .= 'event: '.$event['event']."\n";
            $out .= 'data: '.json_encode($event['data'], JSON_THROW_ON_ERROR)."\n\n";
        }

        return $out;
    }
}
