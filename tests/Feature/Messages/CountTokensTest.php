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

final class CountTokensTest extends TestCase
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
            ],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function count_tokens_passes_through_anthropic_response(): void
    {
        $anthropicBody = json_encode(['input_tokens' => 42]);

        Http::fake([
            'api.anthropic.com/v1/messages/count_tokens' => Http::response($anthropicBody, 200, [
                'request-id' => 'req_ct_abc',
                'content-type' => 'application/json',
            ]),
        ]);

        $response = $this->postJson('/api/v1/messages/count_tokens', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hello world']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-Gateway-Request-Id');
        $response->assertHeader('X-Gateway-Estimated-Cost-USD');

        $body = json_decode($response->getContent(), true);
        $this->assertSame(42, $body['input_tokens']);
    }

    #[Test]
    public function count_tokens_does_not_insert_into_requests_table(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages/count_tokens' => Http::response(
                json_encode(['input_tokens' => 10]),
                200,
                ['request-id' => 'req_ct_nolog'],
            ),
        ]);

        $this->postJson('/api/v1/messages/count_tokens', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Test']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ])->assertStatus(200);

        $this->assertDatabaseMissing('requests', [
            'client_id' => $this->client->id,
            'endpoint' => 'count_tokens',
        ]);
    }

    #[Test]
    public function count_tokens_does_not_mutate_spend(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages/count_tokens' => Http::response(
                json_encode(['input_tokens' => 500]),
                200,
                ['request-id' => 'req_ct_nospend'],
            ),
        ]);

        $spendBefore = (float) $this->client->current_month_spend_usd;

        $this->postJson('/api/v1/messages/count_tokens', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Test']],
            'max_tokens' => 1024,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ])->assertStatus(200);

        $this->client->refresh();
        $this->assertSame($spendBefore, (float) $this->client->current_month_spend_usd);
    }

    #[Test]
    public function count_tokens_without_auth_returns_401(): void
    {
        $response = $this->postJson('/api/v1/messages/count_tokens', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 1024,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function count_tokens_validation_error_returns_400(): void
    {
        $response = $this->postJson('/api/v1/messages/count_tokens', [
            'model' => 'claude-sonnet',
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(400);
    }

    #[Test]
    public function count_tokens_includes_estimated_cost_header(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages/count_tokens' => Http::response(
                json_encode(['input_tokens' => 1000]),
                200,
                ['request-id' => 'req_ct_est'],
            ),
        ]);

        $response = $this->postJson('/api/v1/messages/count_tokens', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'Estimate me']],
            'max_tokens' => 2048,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);
        $estimatedCost = $response->headers->get('X-Gateway-Estimated-Cost-USD');
        $this->assertNotNull($estimatedCost);
        $this->assertGreaterThan(0, (float) $estimatedCost);
    }
}
