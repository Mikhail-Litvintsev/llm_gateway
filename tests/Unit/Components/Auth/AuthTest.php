<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Auth;

use App\Components\Auth\Auth;
use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function rotate_api_key_changes_hash_and_prefix_and_returns_new_key(): void
    {
        $hasher = new KeyHasher('test-pepper-for-unit-tests');
        $generator = new KeyGenerator;
        $auth = new Auth($hasher, $generator);

        $oldKey = $generator->generateRawKey();
        $client = $this->createClient($hasher, $generator, $oldKey);
        $oldHash = $client->api_key_hash;

        $newRawKey = $auth->rotateApiKey($client);

        $this->assertStringStartsWith('gw_live_', $newRawKey);
        $this->assertNotSame($oldKey, $newRawKey);

        $client->refresh();
        $this->assertNotSame($oldHash, $client->api_key_hash);
        $this->assertSame($hasher->hash($newRawKey), $client->api_key_hash);
        $this->assertSame(substr($newRawKey, 0, 12), $client->api_key_prefix);
    }

    #[Test]
    public function rotate_signing_secret_preserves_previous_and_sets_rotated_at(): void
    {
        $hasher = new KeyHasher('test-pepper-for-unit-tests');
        $generator = new KeyGenerator;
        $auth = new Auth($hasher, $generator);

        $initialSecret = 'whsec_initial';
        $client = $this->createClientWithSecret($hasher, $generator, $initialSecret);

        $newSecret = $auth->rotateSigningSecret($client);

        $this->assertStringStartsWith('whsec_', $newSecret);
        $this->assertNotSame($initialSecret, $newSecret);

        $client->refresh();
        $this->assertNotNull($client->signing_secret_current_encrypted);
        $this->assertSame($newSecret, Crypt::decryptString($client->signing_secret_current_encrypted));
        $this->assertNotNull($client->signing_secret_previous_encrypted);
        $this->assertSame($initialSecret, Crypt::decryptString($client->signing_secret_previous_encrypted));
        $this->assertNotNull($client->signing_secret_rotated_at);
    }

    #[Test]
    public function rotate_signing_secret_when_current_is_empty_moves_empty_string_to_previous(): void
    {
        $hasher = new KeyHasher('test-pepper-for-unit-tests');
        $generator = new KeyGenerator;
        $auth = new Auth($hasher, $generator);

        $client = $this->createClientWithSecret($hasher, $generator, '');

        $newSecret = $auth->rotateSigningSecret($client);

        $this->assertStringStartsWith('whsec_', $newSecret);

        $client->refresh();
        $this->assertSame('', $client->signing_secret_previous_encrypted);
        $this->assertNotNull($client->signing_secret_current_encrypted);
        $this->assertNotSame('', Crypt::decryptString($client->signing_secret_current_encrypted));
    }

    private function createClient(KeyHasher $hasher, KeyGenerator $generator, string $rawKey): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'auth-test-workspace-'.bin2hex(random_bytes(4)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'auth-test-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($rawKey),
            'api_key_prefix' => $generator->derivePrefix($rawKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_initial'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }

    private function createClientWithSecret(KeyHasher $hasher, KeyGenerator $generator, string $secret): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'rotate-secret-test-workspace-'.bin2hex(random_bytes(4)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        $rawKey = $generator->generateRawKey();

        return Client::create([
            'name' => 'rotate-secret-test',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($rawKey),
            'api_key_prefix' => $generator->derivePrefix($rawKey),
            'signing_secret_current_encrypted' => $secret === '' ? '' : Crypt::encryptString($secret),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }
}
