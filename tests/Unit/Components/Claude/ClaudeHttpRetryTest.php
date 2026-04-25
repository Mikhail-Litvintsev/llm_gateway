<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude;

use App\Components\Claude\Claude;
use App\Components\Claude\DTO\SendMessageInput;
use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Components\RateLimiting\Claude\ClaudeRateLimitTracker;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ClaudeHttpRetryTest extends TestCase
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
    public function send_message_retries_on_503_and_succeeds_on_third_attempt(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push('upstream temporarily down', 503)
                ->push('still down', 503)
                ->push(json_encode([
                    'id' => 'msg_x',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'model' => 'claude-sonnet-4-6',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                    'stop_reason' => 'end_turn',
                ]), 200),
        ]);

        $output = $this->callSendMessage();

        $this->assertTrue($output->isSuccess);
        $this->assertCount(3, Http::recorded());
    }

    #[Test]
    public function send_message_does_not_retry_on_400(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'bad payload']]),
                400,
            ),
        ]);

        $output = $this->callSendMessage();

        $this->assertFalse($output->isSuccess);
        $this->assertSame(400, $output->envelope->httpStatusCode);
        $this->assertCount(1, Http::recorded());
    }

    #[Test]
    public function send_message_retries_on_429_and_respects_retry_after_header(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push('rate limit', 429, ['retry-after' => '1'])
                ->push(json_encode([
                    'id' => 'msg_y',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'model' => 'claude-sonnet-4-6',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                    'stop_reason' => 'end_turn',
                ]), 200),
        ]);

        $start = microtime(true);
        $output = $this->callSendMessage();
        $elapsed = microtime(true) - $start;

        $this->assertTrue($output->isSuccess);
        $this->assertGreaterThanOrEqual(0.9, $elapsed, 'Retry-After=1 should add ~1s sleep');
        $this->assertLessThan(3.0, $elapsed, 'Retry-After=1 should not fall back to exponential backoff');
        $this->assertCount(2, Http::recorded());
    }

    #[Test]
    public function send_message_returns_5xx_response_after_exhausting_retries(): void
    {
        config()->set('llm.claude.http_retry.max_attempts', 2);
        config()->set('llm.claude.http_retry.base_delay_ms', 1);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response('persistent 502', 502),
        ]);

        $output = $this->callSendMessage();

        $this->assertFalse($output->isSuccess);
        $this->assertSame(502, $output->envelope->httpStatusCode);
        $this->assertCount(2, Http::recorded());
    }

    #[Test]
    public function send_message_retries_on_529_with_exponential_backoff_and_succeeds_on_third_attempt(): void
    {
        config()->set('llm.claude.http_retry.base_delay_ms', 200);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push('overloaded', 529)
                ->push('still overloaded', 529)
                ->push(json_encode([
                    'id' => 'msg_529',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'model' => 'claude-sonnet-4-6',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                    'stop_reason' => 'end_turn',
                ]), 200),
        ]);

        $start = microtime(true);
        $output = $this->callSendMessage();
        $elapsed = microtime(true) - $start;

        $this->assertTrue($output->isSuccess);
        $this->assertCount(3, Http::recorded());
        $this->assertGreaterThanOrEqual(0.55, $elapsed, 'Exponential backoff: 200ms + 400ms between attempts → at least ~0.6s');
        $this->assertLessThan(2.0, $elapsed, 'Backoff must not exceed expected exponential growth');
    }

    #[Test]
    public function send_message_retries_on_529_and_respects_retry_after_header(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::sequence()
                ->push('overloaded', 529, ['retry-after' => '1'])
                ->push(json_encode([
                    'id' => 'msg_529_ra',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'model' => 'claude-sonnet-4-6',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                    'stop_reason' => 'end_turn',
                ]), 200),
        ]);

        $start = microtime(true);
        $output = $this->callSendMessage();
        $elapsed = microtime(true) - $start;

        $this->assertTrue($output->isSuccess);
        $this->assertGreaterThanOrEqual(0.9, $elapsed, 'Retry-After=1 should add ~1s sleep');
        $this->assertLessThan(3.0, $elapsed, 'Retry-After=1 should not fall back to exponential backoff');
        $this->assertCount(2, Http::recorded());
    }

    #[Test]
    public function send_message_returns_529_response_after_exhausting_retries(): void
    {
        config()->set('llm.claude.http_retry.max_attempts', 2);
        config()->set('llm.claude.http_retry.base_delay_ms', 1);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response('persistent overloaded', 529),
        ]);

        $output = $this->callSendMessage();

        $this->assertFalse($output->isSuccess);
        $this->assertSame(529, $output->envelope->httpStatusCode);
        $this->assertCount(2, Http::recorded());
    }

    private function callSendMessage()
    {
        $payload = new BuiltPayload(
            jsonBody: json_encode(['model' => 'claude-sonnet', 'messages' => [['role' => 'user', 'content' => 'hi']], 'max_tokens' => 100]),
            betaHeaders: [],
            modelSnapshot: 'claude-sonnet-4-6',
            modelAlias: 'claude-sonnet',
            payloadSizeBytes: 100,
            decodedPayload: ['model' => 'claude-sonnet', 'messages' => [['role' => 'user', 'content' => 'hi']], 'max_tokens' => 100],
        );

        $input = new SendMessageInput(
            payload: $payload,
            client: $this->client,
            gatewayRequestId: 'req_test_'.bin2hex(random_bytes(8)),
            featuresUsed: [],
        );

        return app(Claude::class)->sendMessage($input);
    }

    private function seedClient(): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'http-retry-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'http-retry-client',
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
