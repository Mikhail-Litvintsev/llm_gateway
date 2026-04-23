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

final class ClientRotateSecretCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function rotates_secret_and_prints_plain_secret_once(): void
    {
        $hasher = app(KeyHasher::class);
        $generator = app(KeyGenerator::class);

        $client = $this->createClient($hasher, $generator, 'whsec_oldsecret');

        $this->artisan('client:rotate-secret', ['client_id' => $client->id])
            ->expectsOutputToContain('NEW SIGNING SECRET')
            ->expectsOutputToContain('whsec_')
            ->assertSuccessful();

        $client->refresh();
        $this->assertNotNull($client->signing_secret_previous_encrypted);
        $this->assertSame(
            'whsec_oldsecret',
            Crypt::decryptString($client->signing_secret_previous_encrypted),
        );
        $this->assertNotNull($client->signing_secret_current_encrypted);
        $this->assertNotSame(
            'whsec_oldsecret',
            Crypt::decryptString($client->signing_secret_current_encrypted),
        );
        $this->assertNotNull($client->signing_secret_rotated_at);
    }

    #[Test]
    public function fails_when_client_not_found(): void
    {
        $this->artisan('client:rotate-secret', ['client_id' => 999999])
            ->expectsOutputToContain('Client not found')
            ->assertFailed();
    }

    private function createClient(KeyHasher $hasher, KeyGenerator $generator, string $initialSecret): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'rotate-secret-cmd-workspace-'.bin2hex(random_bytes(4)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        $rawKey = $generator->generateRawKey();

        return Client::create([
            'name' => 'rotate-secret-test',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($rawKey),
            'api_key_prefix' => $generator->derivePrefix($rawKey),
            'signing_secret_current_encrypted' => Crypt::encryptString($initialSecret),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }
}
