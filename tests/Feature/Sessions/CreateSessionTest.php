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

final class CreateSessionTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

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

    #[Test]
    public function post_creates_session_and_returns_201_with_public_id(): void
    {
        $response = $this->postJson('/api/v1/sessions', [
            'model_alias' => 'claude-sonnet',
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(201);

        $body = $response->json('data');
        $this->assertArrayHasKey('session_id', $body);
        $this->assertStringStartsWith('sess_', $body['session_id']);
        $this->assertSame('claude-sonnet', $body['model_alias']);
    }

    #[Test]
    public function invalid_model_alias_returns_422(): void
    {
        $response = $this->postJson('/api/v1/sessions', [
            'model_alias' => 'nonexistent',
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/v1/sessions', [
            'model_alias' => 'claude-sonnet',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function delete_session_returns_204(): void
    {
        $createResponse = $this->postJson('/api/v1/sessions', [
            'model_alias' => 'claude-sonnet',
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $createResponse->assertStatus(201);
        $sessionId = $createResponse->json('data.session_id');

        $deleteResponse = $this->deleteJson("/api/v1/sessions/{$sessionId}", [], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $deleteResponse->assertStatus(204);
    }
}
