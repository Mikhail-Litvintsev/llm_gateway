<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude;

use App\Components\Claude\Claude;
use App\Components\Claude\DTO\SendMessageInput;
use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Components\RateLimiting\Claude\ClaudeRateLimitTracker;
use App\Components\RateLimiting\Claude\Exceptions\RateLimitExceededException;
use App\Components\RateLimiting\Claude\RateLimitNamespace;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ClaudePreventiveRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->seedClient();
    }

    #[Test]
    public function send_message_throws_before_http_call_when_can_proceed_throws(): void
    {
        $this->mock(ClaudeRateLimitTracker::class, function ($mock) {
            $mock->shouldReceive('canProceed')
                ->once()
                ->andThrow(new RateLimitExceededException('input_tokens', 7));
        });

        Http::fake();

        $this->expectException(RateLimitExceededException::class);

        try {
            app(Claude::class)->sendMessage($this->buildInput());
        } finally {
            $this->assertCount(0, Http::recorded(), 'No HTTP request must be sent if canProceed throws');
        }
    }

    #[Test]
    public function count_tokens_throws_before_http_call(): void
    {
        $this->mock(ClaudeRateLimitTracker::class, function ($mock) {
            $mock->shouldReceive('canProceed')
                ->once()
                ->andThrow(new RateLimitExceededException('requests', 5));
        });

        Http::fake();

        $this->expectException(RateLimitExceededException::class);

        try {
            $payload = $this->buildInput()->payload;
            app(Claude::class)->countTokens($payload, $this->client);
        } finally {
            $this->assertCount(0, Http::recorded());
        }
    }

    #[Test]
    public function can_proceed_called_with_correct_namespace_and_token_estimates(): void
    {
        $this->mock(ClaudeRateLimitTracker::class, function ($mock) {
            $mock->shouldReceive('canProceed')
                ->once()
                ->withArgs(function (
                    RateLimitNamespace $ns,
                    string $hash,
                    string $snapshot,
                    int $inputTokens,
                    int $outputTokens,
                    int $cacheReadTokens,
                ) {
                    return $ns === RateLimitNamespace::Messages
                        && $snapshot === 'claude-sonnet-4-6'
                        && $inputTokens === 1234
                        && $outputTokens === 256
                        && $cacheReadTokens === 0;
                })
                ->andReturn(null);
            $mock->shouldReceive('recordFromHeaders')->andReturn(null);
        });

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                json_encode([
                    'id' => 'msg_a', 'type' => 'message', 'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'model' => 'claude-sonnet-4-6',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                    'stop_reason' => 'end_turn',
                ]),
                200,
            ),
        ]);

        $input = $this->buildInput(estimatedInput: 1234, estimatedOutput: 256);

        $output = app(Claude::class)->sendMessage($input);

        $this->assertTrue($output->isSuccess);
    }

    private function buildInput(int $estimatedInput = 0, int $estimatedOutput = 0): SendMessageInput
    {
        $payload = new BuiltPayload(
            jsonBody: json_encode(['model' => 'claude-sonnet', 'messages' => [['role' => 'user', 'content' => 'hi']], 'max_tokens' => 100]),
            betaHeaders: [],
            modelSnapshot: 'claude-sonnet-4-6',
            modelAlias: 'claude-sonnet',
            payloadSizeBytes: 100,
            decodedPayload: [],
        );

        return new SendMessageInput(
            payload: $payload,
            client: $this->client,
            gatewayRequestId: 'req_test_'.bin2hex(random_bytes(8)),
            featuresUsed: [],
            estimatedInputTokens: $estimatedInput,
            estimatedOutputTokens: $estimatedOutput,
            expectedCacheReadTokens: 0,
        );
    }

    private function seedClient(): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'prl-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'prl-client',
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
