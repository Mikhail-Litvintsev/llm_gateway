<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Components\Claude\ToolTypeCatalog;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NOTE: ServerToolPricing::priceServerTools (the free-hours consumption logic) is currently
 * not wired into the billing pipeline, and SendMessageAction::buildLoggingRecord does not
 * propagate server-tool counts into request_usage. So this test verifies the reachable
 * invariants: endpoint accepts combined tools, payload is forwarded with both tools intact,
 * and workspace_feature_usage pool stays untouched when code_execution is combined with
 * web_search. Stronger billing/count assertions are deferred to the phase that wires
 * ServerToolPricing into CostCalculator and counts into LoggingRecord.
 */
final class ServerToolCodeExecutionTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    private ClaudeWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $generator = new KeyGenerator;
        $this->rawApiKey = $generator->generateRawKey();

        $hasher = $this->app->make(KeyHasher::class);
        $hash = $hasher->hash($this->rawApiKey);

        $this->workspace = ClaudeWorkspace::create([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test-key'),
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'test-client',
            'workspace_id' => $this->workspace->id,
            'api_key_hash' => $hash,
            'api_key_prefix' => $generator->derivePrefix($this->rawApiKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('test-signing-secret'),
            'allowed_features' => [
                'thinking' => true,
                'web_search' => true,
                'prompt_caching' => true,
                'citations' => true,
                'code_execution' => true,
                'structured_outputs' => true,
                'priority_tier' => true,
            ],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function code_execution_combined_with_web_search_is_accepted_and_forwarded(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(json_encode([
                'id' => 'msg_ce_combined',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'model' => 'claude-sonnet-4-6',
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                    'cache_read_input_tokens' => 0,
                    'cache_creation_input_tokens' => 0,
                    'server_tool_use' => [
                        ['type' => 'code_execution', 'count' => 2],
                        ['type' => 'web_search', 'count' => 1],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), 200, ['request-id' => 'req_ce_combined']),
        ]);

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'max_tokens' => 1024,
            'messages' => [['role' => 'user', 'content' => 'Run it']],
            'tools' => [
                ['type' => ToolTypeCatalog::WEB_SEARCH, 'name' => 'web_search'],
                ['type' => ToolTypeCatalog::CODE_EXECUTION, 'name' => 'code_execution'],
            ],
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $types = array_column($body['tools'] ?? [], 'type');

            return in_array(ToolTypeCatalog::WEB_SEARCH, $types, true)
                && in_array(ToolTypeCatalog::CODE_EXECUTION, $types, true);
        });

        $this->assertTrue(ToolTypeCatalog::codeExecutionIsFree([
            ToolTypeCatalog::WEB_SEARCH,
            ToolTypeCatalog::CODE_EXECUTION,
        ]));

        $this->assertSame(0, \DB::table('workspace_feature_usage')->count());
    }

    #[Test]
    public function code_execution_alone_is_accepted_and_forwarded(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(json_encode([
                'id' => 'msg_ce_alone',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'model' => 'claude-sonnet-4-6',
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                    'cache_read_input_tokens' => 0,
                    'cache_creation_input_tokens' => 0,
                    'server_tool_use' => [
                        ['type' => 'code_execution', 'count' => 3],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), 200, ['request-id' => 'req_ce_alone']),
        ]);

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'max_tokens' => 1024,
            'messages' => [['role' => 'user', 'content' => 'Run it']],
            'tools' => [
                ['type' => ToolTypeCatalog::CODE_EXECUTION, 'name' => 'code_execution'],
            ],
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $this->assertFalse(ToolTypeCatalog::codeExecutionIsFree([
            ToolTypeCatalog::CODE_EXECUTION,
        ]));
    }
}
