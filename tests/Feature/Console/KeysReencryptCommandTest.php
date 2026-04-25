<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class KeysReencryptCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $oldKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldKey = 'base64:'.base64_encode(random_bytes(32));
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_OLD_KEY'], $_ENV['APP_OLD_KEY']);
        putenv('APP_OLD_KEY');

        parent::tearDown();
    }

    #[Test]
    public function fails_when_app_old_key_missing(): void
    {
        $this->artisan('keys:reencrypt')
            ->expectsOutputToContain('APP_OLD_KEY environment variable is required.')
            ->assertFailed();
    }

    #[Test]
    public function reencrypts_workspace_and_client_secrets_encrypted_with_old_key(): void
    {
        $oldEncrypter = $this->buildEncrypter($this->oldKey);

        $workspace = ClaudeWorkspace::create([
            'name' => 'reencrypt-target-'.bin2hex(random_bytes(4)),
            'api_key_encrypted' => $oldEncrypter->encryptString('sk-ant-old'),
            'is_active' => true,
        ]);

        $client = Client::create([
            'name' => 'reencrypt-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => str_repeat("\x00", 32),
            'api_key_prefix' => 'gw_live_old1',
            'signing_secret_current_encrypted' => $oldEncrypter->encryptString('whsec-current'),
            'signing_secret_previous_encrypted' => $oldEncrypter->encryptString('whsec-previous'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
        ]);

        $_SERVER['APP_OLD_KEY'] = $this->oldKey;

        $this->artisan('keys:reencrypt')->assertSuccessful();

        $workspace->refresh();
        $client->refresh();

        $this->assertSame('sk-ant-old', Crypt::decryptString($workspace->api_key_encrypted));
        $this->assertSame('whsec-current', Crypt::decryptString($client->signing_secret_current_encrypted ?? ''));
        $this->assertSame('whsec-previous', Crypt::decryptString($client->signing_secret_previous_encrypted ?? ''));
    }

    #[Test]
    public function leaves_already_current_rows_untouched(): void
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'already-current-'.bin2hex(random_bytes(4)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-current'),
            'is_active' => true,
        ]);
        $originalCiphertext = $workspace->api_key_encrypted;

        $_SERVER['APP_OLD_KEY'] = $this->oldKey;

        $this->artisan('keys:reencrypt')->assertSuccessful();

        $workspace->refresh();
        $this->assertSame($originalCiphertext, $workspace->api_key_encrypted);
    }

    #[Test]
    public function dry_run_does_not_modify_rows(): void
    {
        $oldEncrypter = $this->buildEncrypter($this->oldKey);

        $workspace = ClaudeWorkspace::create([
            'name' => 'dry-run-target-'.bin2hex(random_bytes(4)),
            'api_key_encrypted' => $oldEncrypter->encryptString('sk-ant-dry'),
            'is_active' => true,
        ]);
        $originalCiphertext = $workspace->api_key_encrypted;

        $_SERVER['APP_OLD_KEY'] = $this->oldKey;

        $this->artisan('keys:reencrypt', ['--dry-run' => true])->assertSuccessful();

        $workspace->refresh();
        $this->assertSame($originalCiphertext, $workspace->api_key_encrypted);
    }

    #[Test]
    public function fails_when_app_old_key_invalid(): void
    {
        $_SERVER['APP_OLD_KEY'] = 'base64:not-valid-base64-key';

        $this->artisan('keys:reencrypt')
            ->expectsOutputToContain('APP_OLD_KEY is not a valid encryption key')
            ->assertFailed();
    }

    private function buildEncrypter(string $rawKey): Encrypter
    {
        $key = str_starts_with($rawKey, 'base64:')
            ? base64_decode(substr($rawKey, 7))
            : $rawKey;

        return new Encrypter($key, (string) config('app.cipher'));
    }
}
