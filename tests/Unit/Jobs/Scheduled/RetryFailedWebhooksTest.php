<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Scheduled;

use App\Components\Logging\Enums\RequestStatus;
use App\Jobs\DeliverWebhook;
use App\Jobs\Scheduled\RetryFailedWebhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RetryFailedWebhooksTest extends TestCase
{
    use RefreshDatabase;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientId = $this->seedMinimalClient();
    }

    #[Test]
    public function dispatches_pending_requests_with_due_next_attempt_and_nonzero_attempts(): void
    {
        Queue::fake([DeliverWebhook::class]);

        $this->seedRequestWithPending('req_ready_1', attempts: 1, status: 'processing', nextAt: now()->subMinute());
        $this->seedRequestWithPending('req_ready_2', attempts: 3, status: 'processing', nextAt: now()->subSeconds(5));

        (new RetryFailedWebhooks)();

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $j) => $j->requestId === 'req_ready_1');
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $j) => $j->requestId === 'req_ready_2');
        Queue::assertPushed(DeliverWebhook::class, 2);
    }

    #[Test]
    public function does_not_dispatch_first_delivery_with_zero_attempts(): void
    {
        Queue::fake([DeliverWebhook::class]);

        $this->seedRequestWithPending('req_zero', attempts: 0, status: 'processing', nextAt: now()->subMinute());

        (new RetryFailedWebhooks)();

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    #[Test]
    public function skips_delivered_and_exhausted_and_queued(): void
    {
        Queue::fake([DeliverWebhook::class]);

        $this->seedRequestWithPending('req_delivered', attempts: 5, status: 'delivered', nextAt: now()->subMinute());
        $this->seedRequestWithPending('req_exhausted', attempts: 10, status: 'exhausted', nextAt: now()->subMinute());
        $this->seedRequestWithPending('req_queued', attempts: 0, status: 'queued', nextAt: null);

        (new RetryFailedWebhooks)();

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    #[Test]
    public function skips_when_next_attempt_at_is_in_the_future(): void
    {
        Queue::fake([DeliverWebhook::class]);

        $this->seedRequestWithPending('req_future', attempts: 2, status: 'processing', nextAt: now()->addMinutes(5));

        (new RetryFailedWebhooks)();

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    #[Test]
    public function skips_when_attempts_equal_or_exceed_max(): void
    {
        Queue::fake([DeliverWebhook::class]);

        $this->seedRequestWithPending('req_at_max', attempts: 10, status: 'processing', nextAt: now()->subMinute());

        (new RetryFailedWebhooks)();

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    #[Test]
    public function respects_scheduler_batch_size_config(): void
    {
        Queue::fake([DeliverWebhook::class]);
        Config::set('llm.webhook.scheduler_batch_size', 2);

        $this->seedRequestWithPending('req_a', attempts: 1, status: 'processing', nextAt: now()->subMinute());
        $this->seedRequestWithPending('req_b', attempts: 1, status: 'processing', nextAt: now()->subMinute());
        $this->seedRequestWithPending('req_c', attempts: 1, status: 'processing', nextAt: now()->subMinute());

        (new RetryFailedWebhooks)();

        Queue::assertPushed(DeliverWebhook::class, 2);
    }

    private function seedMinimalClient(): int
    {
        $workspaceId = DB::table('claude_workspaces')->insertGetId([
            'name' => 'rfw-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('clients')->insertGetId([
            'name' => 'rfw-client',
            'workspace_id' => $workspaceId,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'gw_live_xxx',
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_x'),
            'allowed_features' => json_encode([]),
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedRequestWithPending(
        string $requestId,
        int $attempts,
        string $status,
        ?Carbon $nextAt,
    ): void {
        DB::table('requests')->insert([
            'request_id' => $requestId,
            'client_id' => $this->clientId,
            'endpoint' => 'messages',
            'mode' => 'async_callback',
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => RequestStatus::Completed->value,
            'created_at' => now(),
        ]);

        DB::table('async_pending')->insert([
            'request_id' => $requestId,
            'payload_for_anthropic' => '{}',
            'callback_url' => 'http://test.example/hook',
            'status' => $status,
            'callback_attempts' => $attempts,
            'next_attempt_at' => $nextAt,
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
