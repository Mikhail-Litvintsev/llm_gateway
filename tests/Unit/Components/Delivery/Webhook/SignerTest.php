<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Delivery\Webhook;

use App\Components\Delivery\Webhook\DTO\WebhookEnvelope;
use App\Components\Delivery\Webhook\Enums\WebhookEvent;
use App\Components\Delivery\Webhook\Exceptions\SecretUnavailableException;
use App\Components\Delivery\Webhook\Signer;
use App\Models\Client;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SignerTest extends TestCase
{
    private Signer $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new Signer();
    }

    #[Test]
    public function sign_and_verify_roundtrip(): void
    {
        $secret = 'test-signing-secret-32bytes!!!!!';
        $client = $this->makeClient(currentSecret: $secret);

        $envelope = new WebhookEnvelope(
            requestId: 'req_001',
            event: WebhookEvent::MessageCompleted,
        );

        $signed = $this->signer->sign($client, $envelope);

        $verified = $this->signer->verify(
            $client,
            $signed->body,
            $signed->headers['X-Webhook-Timestamp'],
            $signed->headers['X-Webhook-Signature'],
        );

        $this->assertTrue($verified);
    }

    #[Test]
    public function sign_includes_required_headers(): void
    {
        $client = $this->makeClient(currentSecret: 'secret123');

        $envelope = new WebhookEnvelope(
            requestId: 'req_002',
            event: WebhookEvent::MessageFailed,
        );

        $signed = $this->signer->sign($client, $envelope);

        $this->assertArrayHasKey('X-Webhook-Signature', $signed->headers);
        $this->assertArrayHasKey('X-Webhook-Timestamp', $signed->headers);
        $this->assertArrayHasKey('X-Webhook-Request-Id', $signed->headers);
        $this->assertArrayHasKey('X-Webhook-Event', $signed->headers);
        $this->assertSame('application/json', $signed->headers['Content-Type']);
        $this->assertSame('req_002', $signed->headers['X-Webhook-Request-Id']);
        $this->assertSame('message.failed', $signed->headers['X-Webhook-Event']);
        $this->assertStringStartsWith('sha256=', $signed->headers['X-Webhook-Signature']);
    }

    #[Test]
    public function verify_fails_on_tampered_body(): void
    {
        $secret = 'tamper-test-secret';
        $client = $this->makeClient(currentSecret: $secret);

        $envelope = new WebhookEnvelope(
            requestId: 'req_003',
            event: WebhookEvent::MessageCompleted,
        );

        $signed = $this->signer->sign($client, $envelope);

        $tampered = str_replace('req_003', 'req_EVIL', $signed->body);

        $this->assertFalse($this->signer->verify(
            $client,
            $tampered,
            $signed->headers['X-Webhook-Timestamp'],
            $signed->headers['X-Webhook-Signature'],
        ));
    }

    #[Test]
    public function verify_fails_with_invalid_signature_prefix(): void
    {
        $client = $this->makeClient(currentSecret: 'some-secret');

        $this->assertFalse($this->signer->verify($client, '{}', '12345', 'md5=abc'));
    }

    #[Test]
    public function verify_fails_with_non_hex_signature(): void
    {
        $client = $this->makeClient(currentSecret: 'some-secret');

        $this->assertFalse($this->signer->verify($client, '{}', '12345', 'sha256=ZZZZ'));
    }

    #[Test]
    public function sign_throws_when_no_secret_configured(): void
    {
        $client = $this->makeClient();

        $envelope = new WebhookEnvelope(
            requestId: 'req_004',
            event: WebhookEvent::MessageCompleted,
        );

        $this->expectException(SecretUnavailableException::class);

        $this->signer->sign($client, $envelope);
    }

    #[Test]
    public function verify_with_previous_secret_during_grace_period(): void
    {
        $oldSecret = 'old-secret-value';
        $newSecret = 'new-secret-value';

        $client = $this->makeClient(
            currentSecret: $newSecret,
            previousSecret: $oldSecret,
            rotatedAt: now()->subMinutes(5),
        );

        config(['llm.webhook.grace_period_seconds' => 86400]);

        $body = '{"test":"data"}';
        $timestamp = (string) time();
        $mac = hash_hmac('sha256', $timestamp . '.' . $body, $oldSecret);

        $this->assertTrue($this->signer->verify(
            $client,
            $body,
            $timestamp,
            'sha256=' . $mac,
        ));
    }

    #[Test]
    public function verify_rejects_previous_secret_after_grace_period(): void
    {
        $oldSecret = 'old-secret-expired';
        $newSecret = 'new-secret-active';

        $client = $this->makeClient(
            currentSecret: $newSecret,
            previousSecret: $oldSecret,
            rotatedAt: now()->subDays(2),
        );

        config(['llm.webhook.grace_period_seconds' => 86400]);

        $body = '{"test":"data"}';
        $timestamp = (string) time();
        $mac = hash_hmac('sha256', $timestamp . '.' . $body, $oldSecret);

        $this->assertFalse($this->signer->verify(
            $client,
            $body,
            $timestamp,
            'sha256=' . $mac,
        ));
    }

    #[Test]
    public function verify_rejects_previous_secret_when_no_rotation_date(): void
    {
        $oldSecret = 'old-secret';
        $newSecret = 'new-secret';

        $client = $this->makeClient(
            currentSecret: $newSecret,
            previousSecret: $oldSecret,
        );

        $body = '{"test":"data"}';
        $timestamp = (string) time();
        $mac = hash_hmac('sha256', $timestamp . '.' . $body, $oldSecret);

        $this->assertFalse($this->signer->verify(
            $client,
            $body,
            $timestamp,
            'sha256=' . $mac,
        ));
    }

    private function makeClient(
        ?string $currentSecret = null,
        ?string $previousSecret = null,
        mixed $rotatedAt = null,
    ): Client {
        $client = new Client();
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
