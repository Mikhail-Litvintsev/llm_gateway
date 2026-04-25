<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Components\Delivery\Webhook\Webhook;
use App\Components\Logging\Enums\RequestStatus;
use App\Jobs\DeliverWebhook;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use App\Repositories\AsyncPendingRepository;
use App\Repositories\RequestRepository;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use TypeError;

final class DeliverWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $requestId;

    private string $callbackUrl = 'http://client.test/hooks/async';

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->seedClient();
        $this->requestId = 'req_'.substr(bin2hex(random_bytes(12)), 0, 24);
        $this->seedCompletedRequest($this->requestId);
    }

    #[Test]
    public function successful_delivery_updates_status_to_delivered(): void
    {
        Http::fake([
            $this->callbackUrl => Http::response('', 200),
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('delivered', $row->status);
        $this->assertSame(1, (int) $row->callback_attempts);
        $this->assertNull($row->next_attempt_at);
    }

    #[Test]
    public function connection_failure_schedules_next_attempt_with_backoff(): void
    {
        Http::fake([
            $this->callbackUrl => function (): void {
                throw new ConnectionException('client unreachable');
            },
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('processing', $row->status);
        $this->assertSame(1, (int) $row->callback_attempts);
        $this->assertNotNull($row->next_attempt_at);

        $expected = now()->addSeconds(10);
        $actual = Carbon::parse($row->next_attempt_at);
        $this->assertLessThanOrEqual(5, abs($expected->diffInSeconds($actual)));
    }

    #[Test]
    public function http_error_response_schedules_retry_without_exception(): void
    {
        Http::fake([
            $this->callbackUrl => Http::response('fail', 500),
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('processing', $row->status);
        $this->assertSame(1, (int) $row->callback_attempts);
    }

    #[Test]
    public function max_attempts_reached_transitions_to_exhausted(): void
    {
        DB::table('async_pending')
            ->where('request_id', $this->requestId)
            ->update(['callback_attempts' => 9, 'status' => 'processing']);

        Http::fake([
            $this->callbackUrl => Http::response('', 500),
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('exhausted', $row->status);
        $this->assertSame(10, (int) $row->callback_attempts);
        $this->assertNull($row->next_attempt_at);

        $requestStatus = DB::table('requests')->where('request_id', $this->requestId)->value('status');
        $this->assertSame(RequestStatus::FailedCallbackDelivery->value, $requestStatus);
    }

    #[Test]
    public function failed_hook_advances_state_machine_and_logs(): void
    {
        $job = new DeliverWebhook($this->requestId);
        $job->failed(new TypeError('boom inside handle'));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('processing', $row->status);
        $this->assertSame(1, (int) $row->callback_attempts);
        $this->assertNotNull($row->next_attempt_at);
    }

    #[Test]
    public function failed_hook_on_last_attempt_transitions_to_exhausted(): void
    {
        DB::table('async_pending')
            ->where('request_id', $this->requestId)
            ->update(['callback_attempts' => 9, 'status' => 'processing']);

        $job = new DeliverWebhook($this->requestId);
        $job->failed(new TypeError('terminal crash'));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('exhausted', $row->status);
        $this->assertSame(10, (int) $row->callback_attempts);

        $requestStatus = DB::table('requests')->where('request_id', $this->requestId)->value('status');
        $this->assertSame(RequestStatus::FailedCallbackDelivery->value, $requestStatus);
    }

    #[Test]
    public function failed_hook_skips_on_already_delivered(): void
    {
        DB::table('async_pending')
            ->where('request_id', $this->requestId)
            ->update(['status' => 'delivered', 'callback_attempts' => 1]);

        $job = new DeliverWebhook($this->requestId);
        $job->failed(new RuntimeException('late crash'));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('delivered', $row->status);
        $this->assertSame(1, (int) $row->callback_attempts);
    }

    #[Test]
    public function backoff_formula_is_exponential_and_capped(): void
    {
        $scenarios = [
            [1, 10],
            [2, 20],
            [5, 160],
            [9, 2560],
            [10, 3600],
        ];

        foreach ($scenarios as [$newAttempts, $expectedDelay]) {
            DB::table('async_pending')
                ->where('request_id', $this->requestId)
                ->update([
                    'callback_attempts' => $newAttempts - 1,
                    'status' => 'processing',
                    'next_attempt_at' => null,
                ]);

            Http::fake([
                $this->callbackUrl => Http::response('fail', 500),
            ]);

            Carbon::setTestNow(Carbon::parse('2026-04-24 12:00:00'));

            $job = new DeliverWebhook($this->requestId);
            $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

            $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();

            if ($newAttempts >= 10) {
                $this->assertSame('exhausted', $row->status, "attempts=$newAttempts should exhaust");
                $this->assertNull($row->next_attempt_at);
            } else {
                $this->assertSame('processing', $row->status, "attempts=$newAttempts stays processing");
                $expected = Carbon::parse('2026-04-24 12:00:00')->addSeconds($expectedDelay);
                $actual = Carbon::parse($row->next_attempt_at);
                $this->assertLessThanOrEqual(
                    3,
                    abs($expected->diffInSeconds($actual)),
                    "attempts=$newAttempts expected delay $expectedDelay s",
                );
            }

            Carbon::setTestNow();
        }
    }

    #[Test]
    public function signature_header_is_signed_with_current_secret(): void
    {
        Http::fake([
            $this->callbackUrl => Http::response('', 200),
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $secret = Crypt::decryptString($this->client->signing_secret_current_encrypted);

        Http::assertSent(function (Request $request) use ($secret): bool {
            $sig = $request->header('X-Webhook-Signature');
            $ts = $request->header('X-Webhook-Timestamp');
            $body = $request->body();

            $sigValue = is_array($sig) ? ($sig[0] ?? '') : $sig;
            $tsValue = is_array($ts) ? ($ts[0] ?? '') : $ts;

            $expected = 'sha256='.hash_hmac('sha256', $tsValue.'.'.$body, $secret);

            return $sigValue === $expected;
        });
    }

    #[Test]
    public function handle_skips_when_already_delivered(): void
    {
        DB::table('async_pending')
            ->where('request_id', $this->requestId)
            ->update(['status' => 'delivered']);

        Http::fake([$this->callbackUrl => Http::response('', 200)]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{int, 'success'|'permanent_fail'|'transient_fail'}>
     */
    public static function statusClassificationProvider(): array
    {
        return [
            '200 → success' => [200, 'success'],
            '201 → success' => [201, 'success'],
            '204 → success' => [204, 'success'],
            '400 → permanent_fail' => [400, 'permanent_fail'],
            '401 → permanent_fail' => [401, 'permanent_fail'],
            '403 → permanent_fail' => [403, 'permanent_fail'],
            '404 → permanent_fail' => [404, 'permanent_fail'],
            '410 → permanent_fail' => [410, 'permanent_fail'],
            '413 → permanent_fail' => [413, 'permanent_fail'],
            '422 → permanent_fail' => [422, 'permanent_fail'],
            '408 → transient_fail' => [408, 'transient_fail'],
            '425 → transient_fail' => [425, 'transient_fail'],
            '429 → transient_fail' => [429, 'transient_fail'],
            '500 → transient_fail' => [500, 'transient_fail'],
            '502 → transient_fail' => [502, 'transient_fail'],
            '503 → transient_fail' => [503, 'transient_fail'],
            '599 → transient_fail' => [599, 'transient_fail'],
        ];
    }

    #[Test]
    #[DataProvider('statusClassificationProvider')]
    public function attempt_delivery_classifies_status_correctly(int $status, string $expectedOutcome): void
    {
        Http::fake([
            $this->callbackUrl => Http::response('body', $status),
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();

        match ($expectedOutcome) {
            'success' => $this->assertSame('delivered', $row->status, "status=$status expected delivered"),
            'permanent_fail' => $this->assertSame('exhausted', $row->status, "status=$status expected exhausted at attempt 1"),
            'transient_fail' => $this->assertSame('processing', $row->status, "status=$status expected processing with scheduleRetry"),
            default => throw new RuntimeException("unexpected outcome `$expectedOutcome`"),
        };

        if ($expectedOutcome === 'permanent_fail') {
            $this->assertSame(1, (int) $row->callback_attempts);
            $this->assertNull($row->next_attempt_at);

            $requestStatus = DB::table('requests')->where('request_id', $this->requestId)->value('status');
            $this->assertSame(RequestStatus::FailedCallbackDelivery->value, $requestStatus);
        }
    }

    #[Test]
    public function permanent_fail_short_circuits_even_near_max_attempts(): void
    {
        DB::table('async_pending')
            ->where('request_id', $this->requestId)
            ->update(['callback_attempts' => 9, 'status' => 'processing']);

        Http::fake([
            $this->callbackUrl => Http::response('bad payload', 400),
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('exhausted', $row->status);
        $this->assertSame(10, (int) $row->callback_attempts);
        $this->assertNull($row->next_attempt_at);

        $requestStatus = DB::table('requests')->where('request_id', $this->requestId)->value('status');
        $this->assertSame(RequestStatus::FailedCallbackDelivery->value, $requestStatus);
    }

    #[Test]
    public function request_exception_is_treated_as_transient_fail(): void
    {
        Http::fake([
            $this->callbackUrl => function (): void {
                throw new RequestException(
                    new ClientResponse(new Psr7Response(500, [], 'boom')),
                );
            },
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('processing', $row->status);
        $this->assertSame(1, (int) $row->callback_attempts);
    }

    #[Test]
    public function permanent_fail_logs_reason_permanent_fail(): void
    {
        $records = $this->captureLlmLogs();

        Http::fake([
            $this->callbackUrl => Http::response('bad', 400),
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $exhausted = array_values(array_filter(
            $records(),
            fn (array $r): bool => $r['message'] === 'Webhook delivery exhausted',
        ));
        $this->assertCount(1, $exhausted, 'expected exactly one exhausted log entry');
        $this->assertSame('permanent_fail', $exhausted[0]['context']['reason'] ?? null);
        $this->assertSame(1, $exhausted[0]['context']['attempts'] ?? null);
    }

    #[Test]
    public function transient_fail_at_max_attempts_logs_reason_transient_fail(): void
    {
        DB::table('async_pending')
            ->where('request_id', $this->requestId)
            ->update(['callback_attempts' => 9, 'status' => 'processing']);

        $records = $this->captureLlmLogs();

        Http::fake([
            $this->callbackUrl => Http::response('boom', 500),
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $exhausted = array_values(array_filter(
            $records(),
            fn (array $r): bool => $r['message'] === 'Webhook delivery exhausted',
        ));
        $this->assertCount(1, $exhausted);
        $this->assertSame('transient_fail', $exhausted[0]['context']['reason'] ?? null);
    }

    /**
     * @return callable(): list<array{message: string, context: array<string, mixed>}>
     */
    private function captureLlmLogs(): callable
    {
        $handler = new TestHandler;

        $manager = app('log');
        $manager->extend('llm_test_capture', fn () => new IlluminateLogger(
            new MonologLogger('llm', [$handler]),
            app('events'),
        ));
        config()->set('logging.channels.llm', ['driver' => 'llm_test_capture']);
        $manager->forgetChannel('llm');

        return static fn (): array => array_map(
            static fn (LogRecord $r): array => [
                'message' => $r->message,
                'context' => $r->context,
            ],
            $handler->getRecords(),
        );
    }

    private function seedClient(): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'dw-test-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'dw-test-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'gw_live_xxx',
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_secret'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }

    private function seedCompletedRequest(string $requestId): void
    {
        DB::table('requests')->insert([
            'request_id' => $requestId,
            'client_id' => $this->client->id,
            'endpoint' => 'messages',
            'mode' => 'async_callback',
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => RequestStatus::Completed->value,
            'http_status' => 200,
            'anthropic_request_id' => 'req_01AN',
            'created_at' => now(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        DB::table('async_pending')->insert([
            'request_id' => $requestId,
            'payload_for_anthropic' => '{"messages":[]}',
            'callback_url' => $this->callbackUrl,
            'status' => 'processing',
            'callback_attempts' => 0,
            'next_attempt_at' => null,
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('request_usage')->insert([
            'request_id' => $requestId,
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cost_usd' => '0.00001000',
            'cost_breakdown' => '{}',
        ]);

        DB::table('request_raw')->insert([
            'request_id' => $requestId,
            'request_payload' => '{}',
            'response_payload' => '{"id":"msg","content":[]}',
            'retention_until' => now()->addDays(14),
        ]);
    }
}
