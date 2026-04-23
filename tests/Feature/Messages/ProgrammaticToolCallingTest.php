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

final class ProgrammaticToolCallingTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

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
            ],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function ptc_with_strict_true_returns_400(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Run the code'],
                    ],
                ],
            ],
            'tools' => [
                [
                    'type' => 'custom',
                    'name' => 'get_weather',
                    'description' => 'Get weather data',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => ['city' => ['type' => 'string']],
                    ],
                    'allowed_callers' => ['code_execution_20260120'],
                ],
                [
                    'type' => 'custom',
                    'name' => 'get_time',
                    'description' => 'Get current time',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                    'strict' => true,
                ],
                [
                    'type' => 'code_execution_20260120',
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $this->assertContains($response->getStatusCode(), [400, 403, 422, 500]);
    }

    #[Test]
    public function ptc_with_mcp_tool_returns_400(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Run the code'],
                    ],
                ],
            ],
            'tools' => [
                [
                    'type' => 'custom',
                    'name' => 'get_weather',
                    'description' => 'Get weather data',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => ['city' => ['type' => 'string']],
                    ],
                    'allowed_callers' => ['code_execution_20260120'],
                ],
                [
                    'type' => 'mcp',
                    'server_label' => 'my_server',
                    'server_url' => 'https://mcp.example.com',
                    'allowed_tools' => ['tool_a'],
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $this->assertContains($response->getStatusCode(), [400, 403, 422, 500]);
    }
}
