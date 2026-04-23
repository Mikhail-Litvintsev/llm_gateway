<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Logging;

use App\Components\Claude\DTO\SendMessageOutput;
use App\Components\Delivery\Sync\DTO\AnthropicResponseEnvelope;
use App\Components\Logging\DTO\LoggingRecord;
use App\Components\Logging\Enums\Endpoint;
use App\Components\Logging\Enums\Mode;
use App\Components\Logging\Enums\RequestStatus;
use App\Components\Logging\Exceptions\IdempotencyException;
use App\Components\Logging\Logging;
use App\Components\Logging\PayloadMasker;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LoggingTest extends TestCase
{
    use RefreshDatabase;

    private Logging $logging;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logging = new Logging();
        $this->clientId = $this->createTestClient();
    }

    #[Test]
    public function record_inserts_three_rows_atomically(): void
    {
        $requestId = str_pad('req_atomic_001', 28, '_');
        $record = $this->makeRecord('req_atomic_001');

        $result = $this->logging->record($record);

        $this->assertSame($requestId, $result->requestId);
        $this->assertSame(1, DB::table('requests')->where('request_id', $requestId)->count());
        $this->assertSame(1, DB::table('request_usage')->where('request_id', $requestId)->count());
        $this->assertSame(1, DB::table('request_raw')->where('request_id', $requestId)->count());
    }

    #[Test]
    public function record_stores_correct_field_values(): void
    {
        $requestId = str_pad('req_fields_001', 28, '_');
        $record = $this->makeRecord('req_fields_001', [
            'inputTokens' => 500,
            'outputTokens' => 200,
            'costUsd' => '0.01050000',
        ]);

        $this->logging->record($record);

        $usage = DB::table('request_usage')->where('request_id', $requestId)->first();
        $this->assertSame(500, (int) $usage->input_tokens);
        $this->assertSame(200, (int) $usage->output_tokens);
    }

    #[Test]
    public function record_rolls_back_if_child_insert_fails(): void
    {
        $requestId = str_pad('req_rollback_01', 28, '_');

        $record = $this->makeRecord($requestId);
        $this->logging->record($record);

        $this->assertSame(1, DB::table('requests')->where('request_id', $requestId)->count());

        $secondRecord = $this->makeRecord($requestId);

        try {
            $this->logging->record($secondRecord);
            $this->fail('Expected IdempotencyException');
        } catch (IdempotencyException) {
            // request_usage should still have exactly 1 row (from first insert)
            $this->assertSame(1, DB::table('request_usage')->where('request_id', $requestId)->count());
        }
    }

    #[Test]
    public function duplicate_request_id_throws_idempotency_exception(): void
    {
        $requestId = str_pad('req_idempotent_01', 28, '_');
        $record = $this->makeRecord($requestId);

        $this->logging->record($record);

        $this->expectException(IdempotencyException::class);
        $this->expectExceptionMessage($requestId);

        $this->logging->record($record);
    }

    #[Test]
    public function payload_masker_redacts_oauth_tokens(): void
    {
        $payload = json_encode([
            'message' => 'hello',
            'oauth_token' => 'secret-value-123',
            'nested' => [
                'authorization' => 'Bearer abc',
                'api_key' => 'sk-12345',
                'data' => 'visible',
            ],
            'token_refresh' => 'refresh-secret',
            'secret_key' => 'my-secret',
        ]);

        $masked = PayloadMasker::mask($payload);
        $decoded = json_decode($masked, true);

        $this->assertSame('hello', $decoded['message']);
        $this->assertSame('[REDACTED]', $decoded['oauth_token']);
        $this->assertSame('[REDACTED]', $decoded['nested']['authorization']);
        $this->assertSame('[REDACTED]', $decoded['nested']['api_key']);
        $this->assertSame('visible', $decoded['nested']['data']);
        $this->assertSame('[REDACTED]', $decoded['token_refresh']);
        $this->assertSame('[REDACTED]', $decoded['secret_key']);
    }

    #[Test]
    public function payload_masker_returns_non_json_as_is(): void
    {
        $raw = 'this is not json';

        $this->assertSame($raw, PayloadMasker::mask($raw));
    }

    #[Test]
    public function update_async_record_updates_existing_row(): void
    {
        $requestId = str_pad('req_async_001', 28, '_');

        DB::table('requests')->insert([
            'request_id' => $requestId,
            'client_id' => $this->clientId,
            'endpoint' => Endpoint::Messages->value,
            'mode' => Mode::AsyncCallback->value,
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => RequestStatus::Accepted->value,
            'http_status' => null,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $envelope = new AnthropicResponseEnvelope(
            httpStatusCode: 200,
            rawBody: '{"content":[]}',
        );

        $output = new SendMessageOutput(
            envelope: $envelope,
            parsedResponse: ['content' => []],
            usage: ['input_tokens' => 100, 'output_tokens' => 50],
            costUsd: 0.005,
            costBreakdown: ['input' => 0.003, 'output' => 0.002],
            serviceTierUsed: 'standard',
            cacheHitTokens: null,
            anthropicRequestId: 'req_ant_123',
            latencyMs: 500,
            isSuccess: true,
        );

        $this->logging->updateAsyncRecord($requestId, $output, ['messages' => []], ['thinking']);

        $row = DB::table('requests')->where('request_id', $requestId)->first();
        $this->assertSame(RequestStatus::Completed->value, $row->status);
        $this->assertSame(200, (int) $row->http_status);
        $this->assertSame('req_ant_123', $row->anthropic_request_id);
        $this->assertNotNull($row->completed_at);

        $this->assertSame(1, DB::table('request_usage')->where('request_id', $requestId)->count());
        $this->assertSame(1, DB::table('request_raw')->where('request_id', $requestId)->count());
    }

    #[Test]
    public function update_async_record_sets_failed_status_on_server_error(): void
    {
        $requestId = str_pad('req_async_err_001', 28, '_');

        DB::table('requests')->insert([
            'request_id' => $requestId,
            'client_id' => $this->clientId,
            'endpoint' => Endpoint::Messages->value,
            'mode' => Mode::AsyncCallback->value,
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => RequestStatus::Accepted->value,
            'http_status' => null,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $envelope = new AnthropicResponseEnvelope(
            httpStatusCode: 500,
            rawBody: '{"error":"internal"}',
        );

        $output = new SendMessageOutput(
            envelope: $envelope,
            parsedResponse: null,
            usage: [],
            costUsd: 0.0,
            costBreakdown: [],
            serviceTierUsed: null,
            cacheHitTokens: null,
            anthropicRequestId: null,
            latencyMs: 100,
            isSuccess: false,
            errorType: 'api_error',
            errorMessage: 'Internal server error',
        );

        $this->logging->updateAsyncRecord($requestId, $output, [], []);

        $row = DB::table('requests')->where('request_id', $requestId)->first();
        $this->assertSame(RequestStatus::FailedServerError->value, $row->status);
    }

    private function createTestClient(): int
    {
        $workspaceId = DB::table('claude_workspaces')->insertGetId([
            'name' => 'ws-logging-' . uniqid(),
            'api_key_encrypted' => Crypt::encryptString('test-key'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('clients')->insertGetId([
            'name' => 'logging-test-client',
            'workspace_id' => $workspaceId,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'llmgw_test_',
            'signing_secret_current_encrypted' => Crypt::encryptString('secret'),
            'allowed_features' => json_encode([]),
            'rate_limit_rpm' => 60,
            'monthly_spend_cap_usd' => null,
            'current_month_spend_usd' => '0.0000',
            'is_dev_mode' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeRecord(string $requestId, array $overrides = []): LoggingRecord
    {
        $requestId = str_pad($requestId, 28, '_');

        $defaults = [
            'requestId' => $requestId,
            'clientId' => $this->clientId,
            'endpoint' => Endpoint::Messages,
            'mode' => Mode::Sync,
            'modelAlias' => 'claude-sonnet',
            'modelSnapshot' => 'claude-sonnet-4-6',
            'anthropicRequestId' => null,
            'anthropicOrganizationId' => null,
            'status' => RequestStatus::Completed,
            'httpStatus' => 200,
            'errorType' => null,
            'errorMessage' => null,
            'serviceTierUsed' => null,
            'createdAt' => new DateTimeImmutable(),
            'startedAt' => new DateTimeImmutable(),
            'completedAt' => new DateTimeImmutable(),
            'inputTokens' => 100,
            'outputTokens' => 50,
            'costUsd' => '0.00150000',
            'requestPayload' => json_encode(['messages' => [['role' => 'user', 'content' => 'hi']]]),
            'responsePayload' => json_encode(['content' => [['type' => 'text', 'text' => 'hello']]]),
            'retentionUntil' => new DateTimeImmutable('+3 days'),
        ];

        $merged = array_merge($defaults, $overrides);

        return new LoggingRecord(...$merged);
    }
}
