<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude;

use App\Components\Claude\Claude;
use App\Components\Claude\DTO\BatchCreateRequest;
use App\Components\Claude\DTO\ResultLine;
use App\Components\Claude\DTO\SendMessageInput;
use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Components\RateLimiting\Claude\ClaudeRateLimitTracker;
use App\Jobs\Claude\SubmitBatchToAnthropic;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ClaudeOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->seedClient();

        $this->mock(ClaudeRateLimitTracker::class, function ($mock): void {
            $mock->shouldReceive('canProceed')->andReturn(null);
            $mock->shouldReceive('recordFromHeaders')->andReturn(null);
        });
    }

    #[Test]
    public function send_message_on_200_returns_success_output_with_parsed_usage_and_cost(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(json_encode([
                'id' => 'msg_orc_1',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'hi']],
                'model' => 'claude-sonnet-4-6',
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 50,
                    'cache_read_input_tokens' => 10,
                    'cache_creation_input_tokens' => 0,
                ],
                'stop_reason' => 'end_turn',
            ]), 200, ['request-id' => 'req_orc_1']),
        ]);

        $output = app(Claude::class)->sendMessage($this->buildInput());

        $this->assertTrue($output->isSuccess);
        $this->assertSame(200, $output->envelope->httpStatusCode);
        $this->assertSame(100, $output->usage['input_tokens']);
        $this->assertSame(50, $output->usage['output_tokens']);
        $this->assertGreaterThan(0, $output->costUsd);

        $expectedKeys = [
            'input', 'output', 'cache_write_5m', 'cache_write_1h',
            'cache_read', 'server_tool_web_search', 'server_tool_code_exec',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $output->costBreakdown);
        }

        $this->assertSame('req_orc_1', $output->anthropicRequestId);
    }

    #[Test]
    public function send_message_on_400_returns_error_output_without_throwing(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(json_encode([
                'type' => 'error',
                'error' => ['type' => 'invalid_request_error', 'message' => 'bad'],
            ]), 400, ['request-id' => 'req_orc_err']),
        ]);

        $output = app(Claude::class)->sendMessage($this->buildInput());

        $this->assertFalse($output->isSuccess);
        $this->assertSame(400, $output->envelope->httpStatusCode);
        $this->assertSame('invalid_request_error', $output->errorType);
        $this->assertSame('bad', $output->errorMessage);
    }

    #[Test]
    public function send_message_on_connection_exception_after_retries_bubbles_up(): void
    {
        config()->set('llm.claude.http_retry.max_attempts', 2);
        config()->set('llm.claude.http_retry.base_delay_ms', 1);

        Http::fake([
            'https://api.anthropic.com/v1/messages' => function (): void {
                throw new ConnectionException('upstream down');
            },
        ]);

        $this->expectException(ConnectionException::class);

        app(Claude::class)->sendMessage($this->buildInput());
    }

    #[Test]
    public function connection_exception_on_each_attempt_triggers_configured_retries(): void
    {
        config()->set('llm.claude.http_retry.max_attempts', 2);
        config()->set('llm.claude.http_retry.base_delay_ms', 1);

        $invocations = 0;
        Http::fake([
            'https://api.anthropic.com/v1/messages' => function () use (&$invocations): void {
                $invocations++;
                throw new ConnectionException('upstream down');
            },
        ]);

        try {
            app(Claude::class)->sendMessage($this->buildInput());
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException) {
            // ok
        }

        $this->assertSame(2, $invocations);
    }

    #[Test]
    public function create_batch_with_submit_immediately_dispatches_job(): void
    {
        Bus::fake([SubmitBatchToAnthropic::class]);

        $request = new BatchCreateRequest(
            requests: [
                [
                    'custom_id' => 'req-1',
                    'params' => [
                        'model' => 'claude-sonnet',
                        'max_tokens' => 100,
                        'messages' => [['role' => 'user', 'content' => 'hi']],
                    ],
                ],
            ],
            submitImmediately: true,
        );

        app(Claude::class)->createBatch($request, $this->client->id);

        Bus::assertDispatched(SubmitBatchToAnthropic::class);
    }

    #[Test]
    public function create_batch_without_submit_immediately_does_not_dispatch(): void
    {
        Bus::fake([SubmitBatchToAnthropic::class]);

        $request = new BatchCreateRequest(
            requests: [
                [
                    'custom_id' => 'req-1',
                    'params' => [
                        'model' => 'claude-sonnet',
                        'max_tokens' => 100,
                        'messages' => [['role' => 'user', 'content' => 'hi']],
                    ],
                ],
            ],
            submitImmediately: false,
        );

        app(Claude::class)->createBatch($request, $this->client->id);

        Bus::assertNotDispatched(SubmitBatchToAnthropic::class);
    }

    #[Test]
    public function get_batch_results_yields_result_lines_from_ndjson_stream(): void
    {
        $body = implode("\n", [
            json_encode(['custom_id' => 'a', 'result' => ['type' => 'succeeded', 'message' => ['id' => 'msg_a']]]),
            json_encode(['custom_id' => 'b', 'result' => ['type' => 'errored', 'error' => ['type' => 'invalid', 'message' => 'x']]]),
            json_encode(['custom_id' => 'c', 'result' => ['type' => 'canceled']]),
        ])."\n";

        Http::fake([
            'https://api.anthropic.com/v1/messages/batches/bat_x/results' => Http::response($body, 200, [
                'content-type' => 'application/x-ndjson',
            ]),
        ]);

        $generator = app(Claude::class)->getBatchResults('bat_x', $this->client);
        $lines = iterator_to_array($generator, preserve_keys: false);

        $this->assertCount(3, $lines);
        $this->assertContainsOnlyInstancesOf(ResultLine::class, $lines);
        $this->assertSame('a', $lines[0]->customId);
        $this->assertSame('succeeded', $lines[0]->type);
        $this->assertSame('errored', $lines[1]->type);
        $this->assertSame('canceled', $lines[2]->type);
    }

    private function buildInput(): SendMessageInput
    {
        $payload = new BuiltPayload(
            jsonBody: json_encode([
                'model' => 'claude-sonnet',
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'max_tokens' => 100,
            ]),
            betaHeaders: [],
            modelSnapshot: 'claude-sonnet-4-6',
            modelAlias: 'claude-sonnet',
            payloadSizeBytes: 100,
            decodedPayload: [
                'model' => 'claude-sonnet',
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'max_tokens' => 100,
            ],
        );

        return new SendMessageInput(
            payload: $payload,
            client: $this->client,
            gatewayRequestId: 'req_orc_'.bin2hex(random_bytes(8)),
            featuresUsed: [],
        );
    }

    private function seedClient(): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'orc-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'orc-client',
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
