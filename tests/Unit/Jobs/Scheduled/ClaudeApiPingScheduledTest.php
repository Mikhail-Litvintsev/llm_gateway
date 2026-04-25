<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Scheduled;

use App\Components\Healthcheck\Enums\HealthStatus;
use App\Components\Routing\WorkspaceResolver;
use App\Jobs\Scheduled\ClaudeApiPingScheduled;
use App\Models\ClaudeWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ClaudeApiPingScheduledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection('cache')->del('claude:healthcheck:anthropic');
    }

    protected function tearDown(): void
    {
        Redis::connection('cache')->del('claude:healthcheck:anthropic');

        parent::tearDown();
    }

    #[Test]
    public function caches_down_with_decrypt_message_when_workspace_decryption_fails(): void
    {
        ClaudeWorkspace::query()->where('name', 'default')->update([
            'api_key_encrypted' => 'corrupted-payload-not-decryptable',
        ]);

        Http::fake();

        (new ClaudeApiPingScheduled)->handle(app(WorkspaceResolver::class));

        $cached = $this->readCached();

        $this->assertSame(HealthStatus::Down->value, $cached['status']);
        $this->assertStringContainsString('cannot be decrypted', $cached['error']);
        $this->assertStringContainsString('runbook', $cached['error']);
        Http::assertNothingSent();
    }

    #[Test]
    public function caches_down_when_no_default_workspace(): void
    {
        ClaudeWorkspace::query()->where('name', 'default')->delete();

        (new ClaudeApiPingScheduled)->handle(app(WorkspaceResolver::class));

        $cached = $this->readCached();

        $this->assertSame(HealthStatus::Down->value, $cached['status']);
        $this->assertSame('no default workspace configured', $cached['error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readCached(): array
    {
        $raw = Redis::connection('cache')->get('claude:healthcheck:anthropic');
        $this->assertIsString($raw, 'Health status should have been cached');

        return json_decode($raw, true);
    }
}
