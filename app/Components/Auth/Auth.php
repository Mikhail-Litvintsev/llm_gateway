<?php

declare(strict_types=1);

namespace App\Components\Auth;

use App\Components\Auth\Exceptions\AuthenticationException;
use App\Models\Client;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

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
        $rawKey = $this->generator->generateRawKey();
        $hash = $this->hasher->hash($rawKey);
        $prefix = $this->generator->derivePrefix($rawKey);

        DB::transaction(function () use ($client, $hash, $prefix): void {
            $client->update([
                'api_key_hash' => $hash,
                'api_key_prefix' => $prefix,
            ]);
        });

        return $rawKey;
    }

    public function rotateSigningSecret(Client $client): string
    {
        $plainSecret = 'whsec_'.bin2hex(random_bytes(32));
        $encryptedSecret = Crypt::encryptString($plainSecret);

        DB::transaction(function () use ($client, $encryptedSecret): void {
            DB::table('clients')
                ->where('id', $client->id)
                ->update([
                    'signing_secret_previous_encrypted' => $client->signing_secret_current_encrypted,
                    'signing_secret_current_encrypted' => $encryptedSecret,
                    'signing_secret_rotated_at' => now(),
                ]);
        });

        return $plainSecret;
    }

    private function extractRawKey(string $bearerToken): string
    {
        if (stripos($bearerToken, 'Bearer ') !== 0) {
            throw new AuthenticationException('Malformed token');
        }

        $rawKey = substr($bearerToken, 7);

        if (! str_starts_with($rawKey, 'gw_live_')) {
            throw new AuthenticationException('Unknown key format');
        }

        return $rawKey;
    }
}
