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

final class ModelsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

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
            'allowed_features' => [],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function index_returns_all_configured_model_aliases(): void
    {
        $response = $this->getJson('/api/v1/models', [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Cache-Control');

        $body = $response->json();
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('has_more', $body);
        $this->assertFalse($body['has_more']);

        $aliases = array_column($body['data'], 'id');
        $configuredAliases = array_keys(config('llm.claude.model_aliases'));

        foreach ($configuredAliases as $expected) {
            $this->assertContains($expected, $aliases);
        }
    }

    #[Test]
    public function index_returns_model_entries_with_correct_structure(): void
    {
        $response = $this->getJson('/api/v1/models', [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $first = $response->json('data.0');
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('type', $first);
        $this->assertSame('model', $first['type']);
        $this->assertArrayHasKey('snapshot', $first);
        $this->assertArrayHasKey('capabilities', $first);
        $this->assertArrayHasKey('pricing', $first);
    }

    #[Test]
    public function index_filters_by_client_allowed_models(): void
    {
        $this->client->update([
            'allowed_features' => [
                'models' => ['claude-sonnet'],
            ],
        ]);

        $response = $this->getJson('/api/v1/models', [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $aliases = array_column($response->json('data'), 'id');
        $this->assertSame(['claude-sonnet'], $aliases);
    }

    #[Test]
    public function show_returns_single_model(): void
    {
        $response = $this->getJson('/api/v1/models/claude-sonnet', [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame('claude-sonnet', $body['id']);
        $this->assertSame('model', $body['type']);
        $this->assertSame(
            config('llm.claude.model_aliases.claude-sonnet'),
            $body['snapshot'],
        );
    }

    #[Test]
    public function show_returns_404_for_unknown_alias(): void
    {
        $response = $this->getJson('/api/v1/models/nonexistent-model', [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(404);

        $body = $response->json();
        $this->assertSame('error', $body['type']);
        $this->assertSame('not_found_error', $body['error']['type']);
    }

    #[Test]
    public function show_returns_404_when_model_not_in_client_whitelist(): void
    {
        $this->client->update([
            'allowed_features' => [
                'models' => ['claude-haiku'],
            ],
        ]);

        $response = $this->getJson('/api/v1/models/claude-sonnet', [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function index_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/v1/models');

        $response->assertStatus(401);
    }

    #[Test]
    public function show_without_auth_returns_401(): void
    {
        $response = $this->getJson('/api/v1/models/claude-sonnet');

        $response->assertStatus(401);
    }

    #[Test]
    public function index_has_first_and_last_id(): void
    {
        $response = $this->getJson('/api/v1/models', [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertArrayHasKey('first_id', $body);
        $this->assertArrayHasKey('last_id', $body);
        $this->assertNotNull($body['first_id']);
        $this->assertNotNull($body['last_id']);
    }
}
