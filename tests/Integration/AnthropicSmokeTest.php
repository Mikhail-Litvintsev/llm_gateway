<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Components\Routing\WorkspaceResolver;
use App\Jobs\Scheduled\ClaudeApiPingScheduled;
use App\Models\Client;
use App\Models\ClaudeWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class AnthropicSmokeTest extends TestCase
{
    use RefreshDatabase;

    private string $plainKey;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('INTEGRATION_ANTHROPIC') !== '1' || empty(env('ANTHROPIC_API_KEY_TEST'))) {
            $this->markTestSkipped('Integration test skipped: set INTEGRATION_ANTHROPIC=1 and ANTHROPIC_API_KEY_TEST.');
        }

        $workspace = ClaudeWorkspace::query()->where('name', 'default')->first();

        if (! $workspace) {
            $workspace = ClaudeWorkspace::create([
                'name' => 'default',
                'api_key_encrypted' => Crypt::encryptString(env('ANTHROPIC_API_KEY_TEST')),
                'is_active' => true,
            ]);
        } else {
            DB::table('claude_workspaces')->where('id', $workspace->id)->update([
                'api_key_encrypted' => Crypt::encryptString(env('ANTHROPIC_API_KEY_TEST')),
                'is_active' => true,
                'updated_at' => now(),
            ]);
        }

        $keyGenerator = app(\App\Components\Auth\KeyGenerator::class);
        $keyHasher = app(\App\Components\Auth\KeyHasher::class);

        $this->plainKey = $keyGenerator->generate();

        $this->client = Client::create([
            'name' => 'integration-test',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $keyHasher->hash($this->plainKey),
            'api_key_prefix' => substr($this->plainKey, 0, 12),
            'signing_secret_current_encrypted' => Crypt::encryptString('test-secret'),
            'rate_limit_rpm' => 600,
            'allowed_features' => ['thinking' => true, 'web_search' => true, 'prompt_caching' => true, 'citations' => true],
            'monthly_spend_cap_usd' => 100.0,
            'current_month_spend_usd' => 0,
        ]);
    }

    public function test_claude_status_command_reports_connected(): void
    {
        app(ClaudeApiPingScheduled::class)->handle(app(WorkspaceResolver::class));

        $cached = json_decode(Redis::connection('cache')->get('claude:healthcheck:anthropic'), true);

        $this->assertNotNull($cached);
        $this->assertSame('ok', $cached['status']);
    }

    public function test_count_tokens_live_returns_positive_integer(): void
    {
        $response = $this->postJson('/api/v1/messages/count_tokens', [
            'model' => 'claude-haiku',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'max_tokens' => 16,
        ], ['Authorization' => 'Bearer ' . $this->plainKey]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertIsInt($data['input_tokens']);
        $this->assertGreaterThan(0, $data['input_tokens']);
    }

    public function test_messages_live_haiku_minimal_roundtrip(): void
    {
        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-haiku',
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Reply with exactly the word "hello".']]]],
            'max_tokens' => 32,
        ], ['Authorization' => 'Bearer ' . $this->plainKey]);

        $response->assertStatus(200);
        $this->assertNotNull($response->headers->get('X-Gateway-Cost-USD'));
        $this->assertGreaterThan(0.0, (float) $response->headers->get('X-Gateway-Cost-USD'));

        $body = $response->json();
        $this->assertSame('message', $body['type']);
        $this->assertNotEmpty($body['content']);
    }
}
