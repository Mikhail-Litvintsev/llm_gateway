<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Components\Authorization\Authorization;
use App\Components\Billing\Billing;
use App\Components\Caching\Caching;
use App\Components\Claude\Claude;
use App\Components\Claude\DTO\SendMessageOutput;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Delivery\Sync\DTO\AnthropicResponseEnvelope;
use App\Components\Logging\Enums\RequestStatus;
use App\Components\Logging\Logging;
use App\Jobs\DeliverWebhook;
use App\Jobs\ProcessAsyncMessage;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ProcessAsyncMessageTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->seedClient();
        $this->requestId = 'req_'.substr(bin2hex(random_bytes(12)), 0, 24);
        $this->seedPendingAsyncRequest($this->requestId);
    }

    #[Test]
    public function handle_successfully_records_and_dispatches_webhook(): void
    {
        Queue::fake([DeliverWebhook::class]);
        $this->mockClaudeSuccess();

        $job = new ProcessAsyncMessage($this->requestId);
        $job->handle(
            app(Claude::class),
            app(PayloadBuilder::class),
            app(Authorization::class),
            app(Billing::class),
            app(Logging::class),
            app(Caching::class),
        );

        $this->assertSame(
            RequestStatus::Completed->value,
            DB::table('requests')->where('request_id', $this->requestId)->value('status'),
        );
        $this->assertTrue(
            DB::table('request_raw')
                ->where('request_id', $this->requestId)
                ->whereNotNull('response_payload')
                ->exists(),
        );
        Queue::assertPushed(
            DeliverWebhook::class,
            fn (DeliverWebhook $job) => $job->requestId === $this->requestId,
        );
    }

    #[Test]
    public function handle_is_idempotent_when_response_already_persisted(): void
    {
        Queue::fake([DeliverWebhook::class]);
        $this->persistPriorSuccessResponse($this->requestId);

        $claudeSpy = $this->mock(Claude::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendMessage');
        });

        $job = new ProcessAsyncMessage($this->requestId);
        $job->handle(
            $claudeSpy,
            app(PayloadBuilder::class),
            app(Authorization::class),
            app(Billing::class),
            app(Logging::class),
            app(Caching::class),
        );

        Queue::assertPushed(DeliverWebhook::class, 1);
        $this->assertSame(
            RequestStatus::Completed->value,
            DB::table('requests')->where('request_id', $this->requestId)->value('status'),
        );
    }

    #[Test]
    public function handle_calls_claude_when_no_persisted_success_exists(): void
    {
        Queue::fake([DeliverWebhook::class]);

        $claudeSpy = $this->mock(Claude::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->andReturn($this->makeSuccessOutput());
        });

        $job = new ProcessAsyncMessage($this->requestId);
        $job->handle(
            $claudeSpy,
            app(PayloadBuilder::class),
            app(Authorization::class),
            app(Billing::class),
            app(Logging::class),
            app(Caching::class),
        );

        Queue::assertPushed(DeliverWebhook::class);
    }

    #[Test]
    public function handle_does_not_retry_on_4xx_and_sends_failure_webhook(): void
    {
        Queue::fake([DeliverWebhook::class]);

        $this->mock(Claude::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->andReturn($this->makeErrorOutput(httpStatus: 400, errorType: 'invalid_request_error'));
        });

        $job = new ProcessAsyncMessage($this->requestId);
        $job->handle(
            app(Claude::class),
            app(PayloadBuilder::class),
            app(Authorization::class),
            app(Billing::class),
            app(Logging::class),
            app(Caching::class),
        );

        $this->assertSame(
            RequestStatus::FailedClientError->value,
            DB::table('requests')->where('request_id', $this->requestId)->value('status'),
        );
        Queue::assertPushed(DeliverWebhook::class);
    }

    #[Test]
    public function failed_marks_request_as_failed_and_dispatches_failure_webhook(): void
    {
        Queue::fake([DeliverWebhook::class]);

        $job = new ProcessAsyncMessage($this->requestId);
        $job->failed(new RuntimeException('simulated upstream crash'));

        $row = DB::table('requests')->where('request_id', $this->requestId)->first();
        $this->assertSame(RequestStatus::FailedServerError->value, $row->status);
        $this->assertSame('async_job_failed', $row->error_type);
        $this->assertStringContainsString('simulated upstream crash', $row->error_message);
        Queue::assertPushed(
            DeliverWebhook::class,
            fn (DeliverWebhook $d) => $d->requestId === $this->requestId,
        );
    }

    #[Test]
    public function middleware_registers_without_overlapping_with_request_id_key(): void
    {
        $job = new ProcessAsyncMessage('req_specific_test_12345');
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('req_specific_test_12345', $middleware[0]->key);
    }

    #[Test]
    public function backoff_returns_exponential_delays(): void
    {
        $job = new ProcessAsyncMessage('req_x');

        $this->assertSame([60, 300, 900], $job->backoff());
        $this->assertSame(3, $job->tries);
    }

    private function seedClient(): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'proc-async-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'proc-async-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'gw_live_xxx',
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_x'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
            'default_model_alias' => 'claude-sonnet',
        ]);
    }

    private function seedPendingAsyncRequest(string $requestId): void
    {
        DB::table('requests')->insert([
            'request_id' => $requestId,
            'client_id' => $this->client->id,
            'endpoint' => 'messages',
            'mode' => 'async_callback',
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => RequestStatus::Accepted->value,
            'created_at' => now(),
        ]);

        DB::table('async_pending')->insert([
            'request_id' => $requestId,
            'payload_for_anthropic' => json_encode([
                'model' => 'claude-sonnet',
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'max_tokens' => 100,
            ]),
            'callback_url' => 'http://example.test/hook',
            'status' => 'queued',
            'callback_attempts' => 0,
            'next_attempt_at' => null,
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function persistPriorSuccessResponse(string $requestId): void
    {
        DB::table('request_raw')->insert([
            'request_id' => $requestId,
            'request_payload' => '{"messages":[]}',
            'response_payload' => json_encode([
                'id' => 'msg_stubbed',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'model' => 'claude-sonnet-4-6',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
                'stop_reason' => 'end_turn',
            ]),
            'retention_until' => now()->addDays(14),
        ]);
    }

    private function mockClaudeSuccess(): void
    {
        $this->mock(Claude::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->andReturn($this->makeSuccessOutput());
        });
    }

    private function makeSuccessOutput(): SendMessageOutput
    {
        return new SendMessageOutput(
            envelope: new AnthropicResponseEnvelope(
                httpStatusCode: 200,
                rawBody: '{"id":"msg_test","content":[{"type":"text","text":"ok"}]}',
                anthropicHeaders: [],
            ),
            parsedResponse: [
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'stop_reason' => 'end_turn',
            ],
            usage: ['input_tokens' => 10, 'output_tokens' => 5],
            costUsd: 0.0001,
            costBreakdown: ['input' => 0.00003, 'output' => 0.00007],
            serviceTierUsed: 'standard',
            cacheHitTokens: 0,
            anthropicRequestId: 'req_01AN',
            latencyMs: 250,
            isSuccess: true,
        );
    }

    private function makeErrorOutput(int $httpStatus, string $errorType): SendMessageOutput
    {
        return new SendMessageOutput(
            envelope: new AnthropicResponseEnvelope(
                httpStatusCode: $httpStatus,
                rawBody: json_encode(['type' => 'error', 'error' => ['type' => $errorType, 'message' => 'bad']]),
                anthropicHeaders: [],
            ),
            parsedResponse: null,
            usage: null,
            costUsd: 0.0,
            costBreakdown: [],
            serviceTierUsed: null,
            cacheHitTokens: null,
            anthropicRequestId: null,
            latencyMs: 10,
            isSuccess: false,
            errorType: $errorType,
            errorMessage: 'bad',
        );
    }
}
