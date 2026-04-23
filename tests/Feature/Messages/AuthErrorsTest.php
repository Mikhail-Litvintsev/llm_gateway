<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\Client;
use App\Models\ClaudeWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm.auth.api_key_pepper' => 'test-pepper']);

        $generator = new KeyGenerator();
        $rawKey = $generator->generateRawKey();
        $hasher = new KeyHasher('test-pepper');

        $workspace = ClaudeWorkspace::create([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        Client::create([
            'name' => 'test-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($rawKey),
            'api_key_prefix' => $generator->derivePrefix($rawKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_test'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function missing_authorization_header_returns_401(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 1024,
        ]);

        $response->assertStatus(401);
        $body = $response->json();
        $this->assertSame('authentication_error', $body['error']['type']);
        $this->assertSame('Unauthorized', $body['error']['message']);
    }

    #[Test]
    public function invalid_api_key_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer gw_live_totallyInvalidKeyThatDoesNotExist00',
        ])->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 1024,
        ]);

        $response->assertStatus(401);
        $body = $response->json();
        $this->assertSame('authentication_error', $body['error']['type']);
    }

    #[Test]
    public function malformed_bearer_token_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Basic some-credentials',
        ])->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 1024,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function key_without_prefix_returns_401(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer not_a_valid_format_key',
        ])->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 1024,
        ]);

        $response->assertStatus(401);
    }
}
