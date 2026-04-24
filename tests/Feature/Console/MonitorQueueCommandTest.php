<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MonitorQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function runs_without_error_on_empty_state(): void
    {
        $this->artisan('queue:monitor')
            ->expectsOutputToContain('Queue depths')
            ->expectsOutputToContain('Failed jobs')
            ->expectsOutputToContain('Stuck async requests')
            ->assertSuccessful();
    }

    #[Test]
    public function reports_stuck_async_requests(): void
    {
        $this->seedRequestWithStuckPending();

        $this->artisan('queue:monitor')
            ->expectsOutputToContain('1 async_pending rows')
            ->assertSuccessful();
    }

    #[Test]
    public function reports_failed_jobs_count(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'test-uuid-1',
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode(['data' => ['commandName' => 'App\\Jobs\\ProcessAsyncMessage']]),
            'exception' => 'TestException',
            'failed_at' => now(),
        ]);

        $this->artisan('queue:monitor')
            ->expectsOutputToContain('total: 1')
            ->expectsOutputToContain('App\\Jobs\\ProcessAsyncMessage')
            ->assertSuccessful();
    }

    private function seedRequestWithStuckPending(): void
    {
        $clientId = $this->seedMinimalClient();
        $requestId = 'req_'.substr(bin2hex(random_bytes(12)), 0, 24);

        DB::table('requests')->insert([
            'request_id' => $requestId,
            'client_id' => $clientId,
            'endpoint' => 'messages',
            'mode' => 'async_callback',
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => 'in_progress',
            'created_at' => now()->subMinutes(10),
            'started_at' => now()->subMinutes(10),
        ]);

        DB::table('async_pending')->insert([
            'request_id' => $requestId,
            'payload_for_anthropic' => '{}',
            'callback_url' => 'http://example.test/hook',
            'status' => 'processing',
            'callback_attempts' => 1,
            'next_attempt_at' => now()->subMinutes(7),
            'expires_at' => now()->addDays(3),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(7),
        ]);
    }

    private function seedMinimalClient(): int
    {
        $workspaceId = DB::table('claude_workspaces')->insertGetId([
            'name' => 'monitor-test-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('test-key'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('clients')->insertGetId([
            'name' => 'monitor-test-client',
            'workspace_id' => $workspaceId,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'gw_live_xxx',
            'signing_secret_current_encrypted' => Crypt::encryptString('secret'),
            'allowed_features' => json_encode([]),
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
