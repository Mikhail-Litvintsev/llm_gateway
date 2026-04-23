<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ValidationErrorsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm.auth.api_key_pepper' => 'test-pepper']);

        $generator = new KeyGenerator;
        $this->rawApiKey = $generator->generateRawKey();
        $hasher = new KeyHasher('test-pepper');

        $workspace = ClaudeWorkspace::create([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'test-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($this->rawApiKey),
            'api_key_prefix' => $generator->derivePrefix($this->rawApiKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_test'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'monthly_spend_cap_usd' => 10.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    private function actAsClient(): void
    {
        $client = $this->client;
        $this->app['router']->matched(function ($event) use ($client): void {
            $event->request->attributes->set('auth.client', $client);
        });
    }

    private function sendMessage(array $payload): TestResponse
    {
        $this->actAsClient();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->rawApiKey,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/messages', $payload);
    }

    #[Test]
    public function unknown_model_alias_returns_400(): void
    {
        $response = $this->sendMessage([
            'model' => 'nonexistent-model',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'max_tokens' => 1024,
        ]);

        $response->assertStatus(400);
        $body = $response->json();
        $this->assertSame('error', $body['type']);
        $this->assertSame('invalid_request_error', $body['error']['type']);
        $this->assertStringContainsString('nonexistent-model', $body['error']['message']);
    }

    #[Test]
    public function missing_messages_field_returns_400(): void
    {
        $response = $this->sendMessage([
            'model' => 'claude-sonnet',
            'max_tokens' => 1024,
        ]);

        $response->assertStatus(400);
        $body = $response->json();
        $this->assertSame('error', $body['type']);
        $this->assertSame('invalid_request_error', $body['error']['type']);
    }

    #[Test]
    public function missing_model_field_returns_400(): void
    {
        $response = $this->sendMessage([
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'max_tokens' => 1024,
        ]);

        $response->assertStatus(400);
        $body = $response->json();
        $this->assertSame('error', $body['type']);
        $this->assertSame('invalid_request_error', $body['error']['type']);
    }

    #[Test]
    public function monthly_spend_cap_exceeded_returns_402(): void
    {
        $this->client->update([
            'monthly_spend_cap_usd' => 10.00,
            'current_month_spend_usd' => 10.00,
        ]);

        $response = $this->sendMessage([
            'model' => 'claude-sonnet',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'max_tokens' => 1024,
        ]);

        $response->assertStatus(402);
        $body = $response->json();
        $this->assertSame('error', $body['type']);
        $this->assertSame('billing_error', $body['error']['type']);
        $this->assertStringContainsString('spend cap', strtolower($body['error']['message']));
    }
}
