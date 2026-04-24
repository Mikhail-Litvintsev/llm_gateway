<?php

declare(strict_types=1);

namespace App\Components\Delivery\Webhook;

use App\Components\Delivery\Webhook\DTO\SignedRequest;
use App\Components\Delivery\Webhook\DTO\WebhookEnvelope;
use App\Components\Delivery\Webhook\Exceptions\SecretUnavailableException;
use App\Models\Client;
use Illuminate\Support\Facades\Crypt;
use JsonException;

final class Signer
{
    /**
     * @throws SecretUnavailableException
     * @throws JsonException
     */
    public function sign(Client $client, WebhookEnvelope $envelope): SignedRequest
    {
        $body = json_encode(
            $envelope->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $timestamp = (string) time();

        $secret = $this->currentSecret($client);
        if ($secret === null) {
            throw new SecretUnavailableException(
                "Client $client->id has no signing_secret_current_encrypted set",
            );
        }

        $mac = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return new SignedRequest(
            body: $body,
            headers: [
                'X-Webhook-Signature' => 'sha256='.$mac,
                'X-Webhook-Timestamp' => $timestamp,
                'X-Webhook-Request-Id' => $envelope->requestId,
                'X-Webhook-Event' => $envelope->event->value,
                'Content-Type' => 'application/json',
            ],
        );
    }

    /**
     * Verify signature AND require the timestamp to be recent.
     *
     * Signature is checked first (constant-time via {@see verify()}); only then the timestamp age is compared.
     * The order is deliberate — reversing it would leak timestamp-age information through a timing side channel.
     *
     * A non-numeric timestamp is rejected explicitly: `(int) "abc"` silently coerces to `0`, which would
     * otherwise be judged by age alone and is semantically meaningless.
     */
    public function verifyWithFreshness(
        Client $client,
        string $body,
        string $timestamp,
        string $signatureHeader,
        ?int $maxAgeSeconds = null,
    ): bool {
        if (! $this->verify($client, $body, $timestamp, $signatureHeader)) {
            return false;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        $max = $maxAgeSeconds ?? (int) config('llm.webhook.timestamp_max_age_seconds', 300);

        return abs(time() - (int) $timestamp) <= $max;
    }

    /**
     * @phpstan-assert non-empty-string $signatureHeader
     */
    public function verify(Client $client, string $body, string $timestamp, string $signatureHeader): bool
    {
        $provided = $this->stripPrefix($signatureHeader);
        if ($provided === null) {
            return false;
        }

        $current = $this->currentSecret($client);
        if ($current !== null) {
            $computed = hash_hmac('sha256', $timestamp.'.'.$body, $current);
            if (hash_equals($computed, $provided)) {
                return true;
            }
        }

        if ($this->isWithinGracePeriod($client)) {
            $previous = $this->previousSecret($client);
            if ($previous !== null) {
                $computed = hash_hmac('sha256', $timestamp.'.'.$body, $previous);
                if (hash_equals($computed, $provided)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function currentSecret(Client $client): ?string
    {
        $encrypted = $client->signing_secret_current_encrypted;

        return $encrypted ? Crypt::decryptString($encrypted) : null;
    }

    private function previousSecret(Client $client): ?string
    {
        $encrypted = $client->signing_secret_previous_encrypted;

        return $encrypted ? Crypt::decryptString($encrypted) : null;
    }

    private function isWithinGracePeriod(Client $client): bool
    {
        if ($client->signing_secret_rotated_at === null) {
            return false;
        }

        $graceSeconds = (int) config('llm.webhook.grace_period_seconds', 86400);

        return now()->diffInSeconds($client->signing_secret_rotated_at, absolute: true) < $graceSeconds;
    }

    private function stripPrefix(string $signatureHeader): ?string
    {
        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return null;
        }

        $hex = substr($signatureHeader, 7);

        if (strlen($hex) !== 64 || ! ctype_xdigit($hex)) {
            return null;
        }

        return $hex;
    }
}
