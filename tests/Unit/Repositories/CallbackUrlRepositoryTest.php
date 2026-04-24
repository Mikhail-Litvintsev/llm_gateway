<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\ClaudeWorkspace;
use App\Models\Client;
use App\Models\ClientCallbackUrl;
use App\Repositories\CallbackUrlRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CallbackUrlRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private CallbackUrlRepository $repo;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = app(CallbackUrlRepository::class);
        $this->client = $this->seedClient('a');
    }

    #[Test]
    public function whitelisted_url_returns_true(): void
    {
        ClientCallbackUrl::create([
            'client_id' => $this->client->id,
            'url' => 'http://example.com/hook',
            'is_active' => true,
        ]);

        $this->assertTrue($this->repo->isWhitelisted($this->client->id, 'http://example.com/hook'));
    }

    #[Test]
    public function missing_url_returns_false(): void
    {
        $this->assertFalse($this->repo->isWhitelisted($this->client->id, 'http://nope.test/hook'));
    }

    #[Test]
    public function deactivated_url_returns_false(): void
    {
        ClientCallbackUrl::create([
            'client_id' => $this->client->id,
            'url' => 'http://example.com/hook',
            'is_active' => false,
        ]);

        $this->assertFalse($this->repo->isWhitelisted($this->client->id, 'http://example.com/hook'));
    }

    #[Test]
    public function different_client_returns_false(): void
    {
        $other = $this->seedClient('b');
        ClientCallbackUrl::create([
            'client_id' => $other->id,
            'url' => 'http://example.com/hook',
            'is_active' => true,
        ]);

        $this->assertFalse($this->repo->isWhitelisted($this->client->id, 'http://example.com/hook'));
    }

    private function seedClient(string $suffix): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'cur-ws-'.$suffix.'-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'cur-client-'.$suffix,
            'workspace_id' => $workspace->id,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'gw_live_'.$suffix,
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_secret'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }
}
