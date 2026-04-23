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

final class StreamingMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

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
            ],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function stream_request_returns_streamed_response_with_sse_headers(): void
    {
        $sseBody = implode("\n\n", [
            "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_stream1\",\"type\":\"message\",\"role\":\"assistant\",\"content\":[],\"model\":\"claude-sonnet-4-6\",\"stop_reason\":null,\"usage\":{\"input_tokens\":10,\"output_tokens\":0,\"cache_read_input_tokens\":0}}}",
            "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}",
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello\"}}",
            "event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}",
            "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":5}}",
            "event: message_stop\ndata: {\"type\":\"message_stop\"}",
            "",
        ]);

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($sseBody, 200, [
                'content-type' => 'text/event-stream',
                'request-id' => 'req_stream_abc',
            ]),
        ]);

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
            'stream' => true,
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(200);
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
        $response->assertHeader('X-Gateway-Request-Id');
        $response->assertHeader('X-Gateway-Model-Alias', 'claude-sonnet');
    }

    #[Test]
    public function stream_upstream_non_200_returns_error(): void
    {
        $errorBody = json_encode([
            'type' => 'error',
            'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
        ]);

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($errorBody, 529, [
                'request-id' => 'req_stream_err',
            ]),
        ]);

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
            'stream' => true,
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $this->assertGreaterThanOrEqual(200, $response->getStatusCode());
        $response->assertHeader('X-Gateway-Request-Id');
    }

    #[Test]
    public function stream_without_auth_returns_401(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
            'stream' => true,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function stream_validation_error_returns_400(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'stream' => true,
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(400);
    }

    #[Test]
    public function stream_spend_cap_exceeded_returns_402(): void
    {
        $this->client->update([
            'current_month_spend_usd' => 1001.00,
        ]);

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
            'stream' => true,
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(402);
    }
}
