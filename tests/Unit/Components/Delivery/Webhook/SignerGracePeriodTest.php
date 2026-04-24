<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Delivery\Webhook;

use App\Components\Delivery\Webhook\Signer;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SignerGracePeriodTest extends TestCase
{
    use RefreshDatabase;

    private Signer $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new Signer;
        config(['llm.webhook.grace_period_seconds' => 86400]);
    }

    #[Test]
    public function verify_accepts_signature_from_current_secret(): void
    {
        $client = $this->seedClient(
            currentSecret: 'newsecret',
            previousSecret: 'oldsecret',
            rotatedAt: null,
        );

        $body = '{"event":"message.completed"}';
        $timestamp = (string) time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'newsecret');

        $this->assertTrue($this->signer->verify($client, $body, $timestamp, $signature));
    }

    #[Test]
    public function verify_accepts_signature_from_previous_secret_within_grace_period(): void
    {
        $client = $this->seedClient(
            currentSecret: 'newsecret',
            previousSecret: 'oldsecret',
            rotatedAt: now()->subHour(),
        );

        $body = '{"event":"message.completed"}';
        $timestamp = (string) time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'oldsecret');

        $this->assertTrue($this->signer->verify($client, $body, $timestamp, $signature));
    }

    #[Test]
    public function verify_rejects_previous_secret_after_grace_period(): void
    {
        $client = $this->seedClient(
            currentSecret: 'newsecret',
            previousSecret: 'oldsecret',
            rotatedAt: now()->subHours(25),
        );

        $body = '{"event":"message.completed"}';
        $timestamp = (string) time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'oldsecret');

        $this->assertFalse($this->signer->verify($client, $body, $timestamp, $signature));
    }

    #[Test]
    public function verify_rejects_garbage_signatures(): void
    {
        $client = $this->seedClient(currentSecret: 'newsecret');

        $body = '{}';
        $timestamp = (string) time();

        $this->assertFalse($this->signer->verify($client, $body, $timestamp, 'sha256='.str_repeat('a', 64)));
        $this->assertFalse($this->signer->verify($client, $body, $timestamp, 'md5='.str_repeat('a', 64)));
        $this->assertFalse($this->signer->verify($client, $body, $timestamp, 'sha256=deadbeef'));
        $this->assertFalse($this->signer->verify($client, $body, $timestamp, 'sha256='.str_repeat('z', 64)));
    }

    #[Test]
    public function verify_rejects_when_only_current_secret_available_and_signature_uses_different_one(): void
    {
        $client = $this->seedClient(
            currentSecret: 'newsecret',
            previousSecret: null,
            rotatedAt: now()->subHour(),
        );

        $body = '{"event":"message.completed"}';
        $timestamp = (string) time();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'some-other-secret');

        $this->assertFalse($this->signer->verify($client, $body, $timestamp, $signature));
    }

    private function seedClient(
        ?string $currentSecret = null,
        ?string $previousSecret = null,
        ?Carbon $rotatedAt = null,
    ): Client {
        $workspace = ClaudeWorkspace::create([
            'name' => 'signer-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'signer-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'gw_live_xxx',
            'signing_secret_current_encrypted' => $currentSecret
                ? Crypt::encryptString($currentSecret)
                : null,
            'signing_secret_previous_encrypted' => $previousSecret
                ? Crypt::encryptString($previousSecret)
                : null,
            'signing_secret_rotated_at' => $rotatedAt,
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }
}
