<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Delivery\Webhook;

use App\Components\Delivery\Webhook\Signer;
use App\Models\Client;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SignerFreshnessTest extends TestCase
{
    private Signer $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new Signer;
    }

    #[Test]
    public function verify_with_freshness_returns_true_when_signature_valid_and_timestamp_fresh(): void
    {
        $secret = 'fresh-secret';
        $client = $this->makeClient(currentSecret: $secret);

        $now = time();
        $body = '{"hello":"world"}';
        $timestamp = (string) ($now - 60);
        $mac = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $this->assertTrue($this->signer->verifyWithFreshness(
            $client,
            $body,
            $timestamp,
            'sha256='.$mac,
            300,
        ));
    }

    #[Test]
    public function verify_with_freshness_returns_false_when_timestamp_older_than_max_age(): void
    {
        $secret = 'stale-secret';
        $client = $this->makeClient(currentSecret: $secret);

        $now = time();
        $body = '{"hello":"stale"}';
        $timestamp = (string) ($now - 600);
        $mac = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $this->assertFalse($this->signer->verifyWithFreshness(
            $client,
            $body,
            $timestamp,
            'sha256='.$mac,
            300,
        ));
    }

    #[Test]
    public function verify_with_freshness_returns_false_when_signature_invalid_even_if_fresh(): void
    {
        $secret = 'tamper-secret';
        $client = $this->makeClient(currentSecret: $secret);

        $now = time();
        $body = '{"hello":"tampered"}';
        $timestamp = (string) ($now - 10);
        $tampered = hash_hmac('sha256', $timestamp.'.'.$body, 'wrong-secret');

        $this->assertFalse($this->signer->verifyWithFreshness(
            $client,
            $body,
            $timestamp,
            'sha256='.$tampered,
            300,
        ));
    }

    #[Test]
    public function verify_with_freshness_accepts_future_timestamp_within_tolerance(): void
    {
        $secret = 'drift-secret';
        $client = $this->makeClient(currentSecret: $secret);

        $now = time();
        $body = '{"drift":"forward"}';
        $timestamp = (string) ($now + 60);
        $mac = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $this->assertTrue($this->signer->verifyWithFreshness(
            $client,
            $body,
            $timestamp,
            'sha256='.$mac,
            300,
        ));
    }

    #[Test]
    public function verify_with_freshness_rejects_future_timestamp_beyond_tolerance(): void
    {
        $secret = 'drift-secret';
        $client = $this->makeClient(currentSecret: $secret);

        $now = time();
        $body = '{"drift":"too-far"}';
        $timestamp = (string) ($now + 600);
        $mac = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $this->assertFalse($this->signer->verifyWithFreshness(
            $client,
            $body,
            $timestamp,
            'sha256='.$mac,
            300,
        ));
    }

    #[Test]
    public function verify_with_freshness_rejects_non_numeric_timestamp(): void
    {
        $secret = 'non-numeric';
        $client = $this->makeClient(currentSecret: $secret);

        $body = '{"abc":1}';
        $timestamp = 'abc';
        $mac = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $this->assertFalse($this->signer->verifyWithFreshness(
            $client,
            $body,
            $timestamp,
            'sha256='.$mac,
            300,
        ));
    }

    #[Test]
    public function verify_with_freshness_uses_config_default_when_arg_null(): void
    {
        $secret = 'config-default';
        $client = $this->makeClient(currentSecret: $secret);

        config(['llm.webhook.timestamp_max_age_seconds' => 60]);

        $now = time();
        $body = '{"uses":"config"}';

        $old = (string) ($now - 100);
        $oldMac = hash_hmac('sha256', $old.'.'.$body, $secret);
        $this->assertFalse($this->signer->verifyWithFreshness($client, $body, $old, 'sha256='.$oldMac));

        $recent = (string) ($now - 30);
        $recentMac = hash_hmac('sha256', $recent.'.'.$body, $secret);
        $this->assertTrue($this->signer->verifyWithFreshness($client, $body, $recent, 'sha256='.$recentMac));
    }

    #[Test]
    public function verify_with_freshness_within_grace_period_accepts_previous_secret_if_fresh(): void
    {
        $oldSecret = 'old-secret';
        $newSecret = 'new-secret';

        $client = $this->makeClient(
            currentSecret: $newSecret,
            previousSecret: $oldSecret,
            rotatedAt: now()->subMinutes(5),
        );

        config(['llm.webhook.grace_period_seconds' => 86400]);

        $now = time();
        $body = '{"rotation":"ok"}';
        $timestamp = (string) ($now - 30);
        $mac = hash_hmac('sha256', $timestamp.'.'.$body, $oldSecret);

        $this->assertTrue($this->signer->verifyWithFreshness(
            $client,
            $body,
            $timestamp,
            'sha256='.$mac,
            300,
        ));
    }

    private function makeClient(
        ?string $currentSecret = null,
        ?string $previousSecret = null,
        mixed $rotatedAt = null,
    ): Client {
        $client = new Client;
        $client->id = 1;
        $client->signing_secret_current_encrypted = $currentSecret
            ? Crypt::encryptString($currentSecret)
            : null;
        $client->signing_secret_previous_encrypted = $previousSecret
            ? Crypt::encryptString($previousSecret)
            : null;
        $client->signing_secret_rotated_at = $rotatedAt;

        return $client;
    }
}
