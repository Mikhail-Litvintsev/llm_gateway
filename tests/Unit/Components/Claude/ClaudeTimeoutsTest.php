<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude;

use App\Components\Claude\Claude;
use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Components\RateLimiting\Claude\ClaudeRateLimitTracker;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ClaudeTimeoutsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->seedClient();

        $this->mock(ClaudeRateLimitTracker::class, function ($mock) {
            $mock->shouldReceive('canProceed')->andReturn(null);
            $mock->shouldReceive('recordFromHeaders')->andReturn(null);
        });
    }

    #[Test]
    public function count_tokens_executes_without_timeout_conflict(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages/count_tokens' => Http::response(
                json_encode(['input_tokens' => 100]),
                200,
            ),
        ]);

        app(Claude::class)->countTokens($this->buildPayload(), $this->client);

        $this->assertCount(1, Http::recorded());
    }

    #[Test]
    public function get_batch_returns_counts_without_timeout_conflict(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages/batches/btch_test_id' => Http::response(
                json_encode([
                    'id' => 'btch_test_id',
                    'created_at' => now()->toIso8601String(),
                    'request_counts' => [
                        'processing' => 0,
                        'succeeded' => 5,
                        'errored' => 0,
                        'canceled' => 0,
                        'expired' => 0,
                    ],
                ]),
                200,
            ),
        ]);

        $batch = app(Claude::class)->getBatch('btch_test_id', $this->client);

        $this->assertSame(5, $batch->succeededCount);
        $this->assertCount(1, Http::recorded());
    }

    private function buildPayload(): BuiltPayload
    {
        return new BuiltPayload(
            jsonBody: json_encode(['model' => 'claude-sonnet', 'messages' => [['role' => 'user', 'content' => 'hi']], 'max_tokens' => 100]),
            betaHeaders: [],
            modelSnapshot: 'claude-sonnet-4-6',
            modelAlias: 'claude-sonnet',
            payloadSizeBytes: 100,
            decodedPayload: [],
        );
    }

    private function seedClient(): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'timeout-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'timeout-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'gw_live_xxx',
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_secret'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }
}
