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

final class SyncMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

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
            ],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function sync_send_happy_path_returns_anthropic_body_and_gateway_headers(): void
    {
        $anthropicBody = json_encode([
            'id' => 'msg_test123',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hello!']],
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'cache_read_input_tokens' => 0,
                'cache_creation_input_tokens' => 0,
            ],
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($anthropicBody, 200, [
                'request-id' => 'req_anthropic_abc',
                'content-type' => 'application/json',
            ]),
        ]);

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-Gateway-Request-Id');
        $response->assertHeader('X-Gateway-Model-Alias', 'claude-sonnet');
        $response->assertHeader('X-Gateway-Model-Snapshot', config('llm.claude.model_aliases.claude-sonnet'));

        $responseBody = $response->getContent();
        $decoded = json_decode($responseBody, true);
        $this->assertSame('msg_test123', $decoded['id']);
        $this->assertSame('message', $decoded['type']);

        $this->assertDatabaseHas('requests', [
            'client_id' => $this->client->id,
            'endpoint' => 'messages',
            'mode' => 'sync',
            'model_alias' => 'claude-sonnet',
            'status' => 'completed',
        ]);

        $gatewayRequestId = $response->headers->get('X-Gateway-Request-Id');
        $this->assertDatabaseHas('request_usage', [
            'request_id' => $gatewayRequestId,
        ]);
        $this->assertDatabaseHas('request_raw', [
            'request_id' => $gatewayRequestId,
        ]);
    }

    #[Test]
    public function sync_send_records_spend_on_client(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(json_encode([
                'id' => 'msg_spend',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'model' => 'claude-sonnet-4-6',
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 50,
                    'cache_read_input_tokens' => 0,
                    'cache_creation_input_tokens' => 0,
                ],
            ]), 200, ['request-id' => 'req_spend_abc']),
        ]);

        $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ])->assertStatus(200);

        $this->client->refresh();
        $this->assertGreaterThan(0, (float) $this->client->current_month_spend_usd);
    }

    #[Test]
    public function unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function invalid_api_key_returns_401(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer gw_live_invalidkeyxxxxxxxxxxxxxx',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function validation_error_returns_400(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(400);

        $body = $response->json();
        $this->assertSame('error', $body['type']);
        $this->assertSame('invalid_request_error', $body['error']['type']);
    }

    #[Test]
    public function unknown_model_alias_returns_error(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'model' => 'nonexistent-model',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $this->assertContains($response->getStatusCode(), [400, 404, 422]);
    }

    #[Test]
    public function upstream_error_returns_error_status_and_logs(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(json_encode([
                'type' => 'error',
                'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
            ]), 529, ['request-id' => 'req_err_abc']),
        ]);

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());

        $gatewayRequestId = $response->headers->get('X-Gateway-Request-Id');
        $this->assertNotNull($gatewayRequestId);

        $this->assertDatabaseHas('requests', [
            'request_id' => $gatewayRequestId,
            'client_id' => $this->client->id,
        ]);
    }

    #[Test]
    public function spend_cap_exceeded_returns_402(): void
    {
        $this->client->update([
            'current_month_spend_usd' => 1001.00,
        ]);

        Http::fake();

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(402);

        $body = $response->json();
        $this->assertSame('billing_error', $body['error']['type']);
    }

    #[Test]
    public function feature_not_allowed_returns_403(): void
    {
        $this->client->update([
            'allowed_features' => [
                'thinking' => false,
            ],
        ]);

        Http::fake();

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
            'thinking' => ['type' => 'enabled', 'budget_tokens' => 5000],
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(403);
    }
}
