<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\ClaudeWorkspace;
use App\Models\Client;
use App\Repositories\AsyncPendingRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AsyncPendingRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private AsyncPendingRepository $repo;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = app(AsyncPendingRepository::class);
        $this->client = $this->seedClient();
    }

    #[Test]
    public function find_returns_null_when_pending_missing(): void
    {
        $this->assertNull($this->repo->find('req_missing_xxxxxxxxxxxx'));
    }

    #[Test]
    public function create_inserts_queued_status_with_defaults(): void
    {
        $this->seedRequest('req_aaaaaaaaaaaaaaaaaaaaaaaa');
        $expiresAt = new DateTimeImmutable('+3 days');

        $this->repo->create(
            'req_aaaaaaaaaaaaaaaaaaaaaaaa',
            'http://example.com/hook',
            '{"messages":[]}',
            $expiresAt,
        );

        $row = $this->repo->find('req_aaaaaaaaaaaaaaaaaaaaaaaa');
        $this->assertNotNull($row);
        $this->assertSame('queued', $row->status);
        $this->assertSame(0, $row->callback_attempts);
        $this->assertNull($row->next_attempt_at);
    }

    #[Test]
    public function mark_processing_updates_status(): void
    {
        $this->seedPending('req_bbbbbbbbbbbbbbbbbbbbbbbb', 'queued');

        $this->repo->markProcessing('req_bbbbbbbbbbbbbbbbbbbbbbbb');

        $this->assertSame('processing', $this->repo->getStatus('req_bbbbbbbbbbbbbbbbbbbbbbbb'));
    }

    #[Test]
    public function mark_delivered_sets_status_and_clears_next_attempt(): void
    {
        $this->seedPending('req_cccccccccccccccccccccccc', 'processing', nextAttemptAt: now()->addMinute());

        $this->repo->markDelivered('req_cccccccccccccccccccccccc', 3);

        $row = $this->repo->find('req_cccccccccccccccccccccccc');
        $this->assertSame('delivered', $row->status);
        $this->assertSame(3, $row->callback_attempts);
        $this->assertNull($row->next_attempt_at);
    }

    #[Test]
    public function mark_exhausted_sets_status_and_clears_next_attempt(): void
    {
        $this->seedPending('req_dddddddddddddddddddddddd', 'processing', nextAttemptAt: now()->addMinute());

        $this->repo->markExhausted('req_dddddddddddddddddddddddd', 10);

        $row = $this->repo->find('req_dddddddddddddddddddddddd');
        $this->assertSame('exhausted', $row->status);
        $this->assertSame(10, $row->callback_attempts);
        $this->assertNull($row->next_attempt_at);
    }

    #[Test]
    public function schedule_retry_updates_status_and_next_attempt(): void
    {
        $this->seedPending('req_eeeeeeeeeeeeeeeeeeeeeeee', 'processing');
        $next = new DateTimeImmutable('+30 seconds');

        $this->repo->scheduleRetry('req_eeeeeeeeeeeeeeeeeeeeeeee', 2, $next);

        $row = $this->repo->find('req_eeeeeeeeeeeeeeeeeeeeeeee');
        $this->assertSame('processing', $row->status);
        $this->assertSame(2, $row->callback_attempts);
        $this->assertNotNull($row->next_attempt_at);
    }

    #[Test]
    public function get_status_returns_string_or_null(): void
    {
        $this->seedPending('req_ffffffffffffffffffffffff', 'queued');

        $this->assertSame('queued', $this->repo->getStatus('req_ffffffffffffffffffffffff'));
        $this->assertNull($this->repo->getStatus('req_missing_xxxxxxxxxxxx'));
    }

    private function seedClient(): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'apr-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'apr-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'gw_live_apr',
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_secret'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }

    private function seedRequest(string $requestId): void
    {
        DB::table('requests')->insert([
            'request_id' => $requestId,
            'client_id' => $this->client->id,
            'endpoint' => 'messages',
            'mode' => 'async_callback',
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => 'accepted',
            'created_at' => now(),
        ]);
    }

    private function seedPending(string $requestId, string $status, mixed $nextAttemptAt = null): void
    {
        $this->seedRequest($requestId);
        DB::table('async_pending')->insert([
            'request_id' => $requestId,
            'payload_for_anthropic' => '{}',
            'callback_url' => 'http://example.com/hook',
            'status' => $status,
            'callback_attempts' => 0,
            'next_attempt_at' => $nextAttemptAt,
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
