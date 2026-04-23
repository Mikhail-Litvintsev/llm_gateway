<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ClientRotateKeyCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function rotates_key_and_prints_plain_key_once(): void
    {
        $hasher = app(KeyHasher::class);
        $generator = app(KeyGenerator::class);

        $oldKey = $generator->generateRawKey();
        $oldPrefix = $generator->derivePrefix($oldKey);
        $client = $this->createClient($hasher, $generator, $oldKey);
        $oldHash = $client->api_key_hash;

        $this->artisan('client:rotate-key', ['client_id' => $client->id])
            ->expectsOutputToContain('NEW API KEY')
            ->expectsOutputToContain('gw_live_')
            ->assertSuccessful();

        $client->refresh();
        $this->assertNotSame($oldHash, $client->api_key_hash);
        $this->assertNotSame($oldPrefix, $client->api_key_prefix);
    }

    #[Test]
    public function fails_when_client_not_found(): void
    {
        $this->artisan('client:rotate-key', ['client_id' => 999999])
            ->expectsOutputToContain('Client not found')
            ->assertFailed();
    }

    private function createClient(KeyHasher $hasher, KeyGenerator $generator, string $rawKey): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'rotate-key-test-workspace-'.bin2hex(random_bytes(4)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'rotation-test',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($rawKey),
            'api_key_prefix' => $generator->derivePrefix($rawKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_initial'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }
}
