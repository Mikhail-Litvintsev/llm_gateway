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

final class CitationsEndToEndTest extends TestCase
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
    public function mixed_citations_enabled_flag_returns_422(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'document',
                            'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => 'Document one content'],
                            'citations' => ['enabled' => true],
                        ],
                        [
                            'type' => 'document',
                            'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => 'Document two content'],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Summarize these documents',
                        ],
                    ],
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $this->assertContains($response->getStatusCode(), [400, 422]);
    }

    #[Test]
    public function citations_with_output_config_returns_422(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'document',
                            'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => 'Document content'],
                            'citations' => ['enabled' => true],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Summarize this',
                        ],
                    ],
                ],
            ],
            'output_config' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => ['summary' => ['type' => 'string']],
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->rawApiKey,
        ]);

        $this->assertContains($response->getStatusCode(), [400, 422]);
    }
}
