<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Components\RateLimiting\Claude\ClaudeRateLimitTracker;
use App\Components\RateLimiting\Claude\Exceptions\RateLimitExceededException;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RateLimit429Test extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $generator = new KeyGenerator;
        $this->rawApiKey = $generator->generateRawKey();

        $hasher = $this->app->make(KeyHasher::class);

        $workspace = ClaudeWorkspace::create([
            'name' => 'rl429-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'rl429-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($this->rawApiKey),
            'api_key_prefix' => $generator->derivePrefix($this->rawApiKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_secret'),
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
    public function sync_messages_returns_429_with_retry_after_header(): void
    {
        $this->mock(ClaudeRateLimitTracker::class, function ($mock) {
            $mock->shouldReceive('canProceed')
                ->andThrow(new RateLimitExceededException('input_tokens', 12));
        });

        Http::fake();

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'max_tokens' => 100,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After', '12');
        $response->assertJsonPath('error.type', 'rate_limit_error');

        Http::assertNothingSent();
    }

    #[Test]
    public function streaming_messages_returns_429_without_opening_sse(): void
    {
        $this->mock(ClaudeRateLimitTracker::class, function ($mock) {
            $mock->shouldReceive('canProceed')
                ->andThrow(new RateLimitExceededException('output_tokens', 8));
        });

        Http::fake();

        $response = $this->postJson('/api/v1/messages', [
            'model' => 'claude-sonnet',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'max_tokens' => 100,
            'stream' => true,
        ], [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After', '8');
        $response->assertJsonPath('error.type', 'rate_limit_error');

        Http::assertNothingSent();
    }
}
