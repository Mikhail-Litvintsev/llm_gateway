<?php

namespace Tests\Unit\Components\Security;

use App\Components\Security\CallbackSigner;
use PHPUnit\Framework\TestCase;

class CallbackSignerTest extends TestCase
{
    private CallbackSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new CallbackSigner();
    }

    public function test_generates_valid_signature(): void
    {
        $body = '{"status":"ok"}';
        $secret = 'test_secret';

        $headers = $this->signer->sign($body, $secret, 'req_001');

        $this->assertArrayHasKey('X-LLM-Signature', $headers);
        $this->assertStringStartsWith('sha256=', $headers['X-LLM-Signature']);
        $this->assertArrayHasKey('X-LLM-Timestamp', $headers);
        $this->assertArrayHasKey('X-LLM-Nonce', $headers);
        $this->assertArrayHasKey('X-LLM-Request-Id', $headers);
        $this->assertEquals('req_001', $headers['X-LLM-Request-Id']);
    }

    public function test_verify_accepts_valid_signature(): void
    {
        $body = '{"status":"ok"}';
        $secret = 'test_secret';

        $headers = $this->signer->sign($body, $secret, 'req_001');

        $result = $this->signer->verify(
            $body,
            $secret,
            $headers['X-LLM-Signature'],
            (int) $headers['X-LLM-Timestamp'],
            $headers['X-LLM-Nonce'],
        );

        $this->assertTrue($result);
    }

    public function test_verify_rejects_expired_timestamp(): void
    {
        $body = '{"status":"ok"}';
        $secret = 'test_secret';
        $nonce = 'test-nonce';
        $oldTimestamp = time() - 600;

        $stringToSign = "{$oldTimestamp}.{$nonce}.{$body}";
        $hmac = hash_hmac('sha256', $stringToSign, $secret);
        $signature = "sha256={$hmac}";

        $result = $this->signer->verify($body, $secret, $signature, $oldTimestamp, $nonce);

        $this->assertFalse($result);
    }

    public function test_verify_rejects_tampered_body(): void
    {
        $body = '{"status":"ok"}';
        $secret = 'test_secret';

        $headers = $this->signer->sign($body, $secret, 'req_001');

        $tamperedBody = '{"status":"hacked"}';
        $result = $this->signer->verify(
            $tamperedBody,
            $secret,
            $headers['X-LLM-Signature'],
            (int) $headers['X-LLM-Timestamp'],
            $headers['X-LLM-Nonce'],
        );

        $this->assertFalse($result);
    }

    public function test_verify_rejects_wrong_secret(): void
    {
        $body = '{"status":"ok"}';

        $headers = $this->signer->sign($body, 'correct_secret', 'req_001');

        $result = $this->signer->verify(
            $body,
            'wrong_secret',
            $headers['X-LLM-Signature'],
            (int) $headers['X-LLM-Timestamp'],
            $headers['X-LLM-Nonce'],
        );

        $this->assertFalse($result);
    }

    public function test_sign_produces_unique_nonces(): void
    {
        $body = '{"status":"ok"}';
        $secret = 'test_secret';

        $headers1 = $this->signer->sign($body, $secret, 'req_001');
        $headers2 = $this->signer->sign($body, $secret, 'req_001');

        $this->assertNotEquals($headers1['X-LLM-Nonce'], $headers2['X-LLM-Nonce']);
    }
}
