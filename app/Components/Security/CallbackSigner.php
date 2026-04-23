<?php

namespace App\Components\Security;

use App\Components\CallbackDelivery\Contracts\CallbackSignerContract;
use App\Components\Security\DTO\SignaturePayload;
use Illuminate\Support\Str;

class CallbackSigner implements CallbackSignerContract
{
    public function sign(string $rawBody, string $signingSecret, string $requestId): array
    {
        $timestamp = time();
        $nonce = (string) Str::uuid();

        $stringToSign = "{$timestamp}.{$nonce}.{$rawBody}";

        $hmac = hash_hmac('sha256', $stringToSign, $signingSecret);

        $payload = new SignaturePayload(
            signature: "sha256={$hmac}",
            timestamp: $timestamp,
            nonce: $nonce,
            requestId: $requestId,
        );

        return $payload->toHeaders();
    }

    public function verify(
        string $rawBody,
        string $signingSecret,
        string $signature,
        int $timestamp,
        string $nonce,
        int $toleranceSeconds = 300,
    ): bool {
        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $stringToSign = "{$timestamp}.{$nonce}.{$rawBody}";
        $expectedHmac = hash_hmac('sha256', $stringToSign, $signingSecret);
        $expectedSignature = "sha256={$expectedHmac}";

        return hash_equals($expectedSignature, $signature);
    }
}
