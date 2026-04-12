<?php

declare(strict_types=1);

namespace App\Components\Auth;

use App\Components\Auth\Exceptions\AuthenticationException;
use App\Models\Client;

final class Auth
{
    public function __construct(
        private readonly KeyHasher $hasher,
        private readonly KeyGenerator $generator,
    ) {}

    public function authenticate(string $bearerToken): Client
    {
        $rawKey = $this->extractRawKey($bearerToken);
        $hash = $this->hasher->hash($rawKey);
        $client = Client::query()->where('api_key_hash', $hash)->first();

        if ($client === null) {
            throw new AuthenticationException('Invalid token');
        }

        return $client;
    }

    public function rotateApiKey(Client $client): string
    {
        throw new \LogicException('Not implemented in Phase 1');
    }

    public function rotateSigningSecret(Client $client): string
    {
        throw new \LogicException('Not implemented in Phase 1');
    }

    public function verifyWebhookSignature(Client $client, string $payload, string $signatureHeader): bool
    {
        throw new \LogicException('Not implemented in Phase 1');
    }

    private function extractRawKey(string $bearerToken): string
    {
        if (stripos($bearerToken, 'Bearer ') !== 0) {
            throw new AuthenticationException('Malformed token');
        }

        $rawKey = substr($bearerToken, 7);

        if (!str_starts_with($rawKey, 'gw_live_')) {
            throw new AuthenticationException('Unknown key format');
        }

        return $rawKey;
    }
}
