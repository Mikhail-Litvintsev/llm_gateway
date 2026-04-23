<?php

namespace App\Components\Auth;

use App\Components\Auth\DTO\AuthenticatedClient;
use App\Models\ApiClient;
use Illuminate\Auth\Access\AuthorizationException;

class ApiAuthenticator
{
    public function __construct(
        private readonly KeyHasher $keyHasher,
        private readonly CallbackUrlValidator $callbackUrlValidator,
    ) {}

    public function authenticate(string $apiKey): AuthenticatedClient
    {
        $hash = $this->keyHasher->hash($apiKey);

        $client = ApiClient::where('api_key_hash', $hash)
            ->where('is_active', true)
            ->first();

        if (!$client) {
            // Check previous key hash for key rotation
            $client = ApiClient::where('previous_key_hash', $hash)
                ->where('is_active', true)
                ->where('previous_key_expires_at', '>', now())
                ->first();
        }

        if (!$client) {
            throw new AuthorizationException('Invalid or revoked API key.');
        }

        return new AuthenticatedClient(
            id: $client->id,
            name: $client->name,
            rateLimit: $client->rate_limit,
            allowedProviders: $client->allowed_providers,
            signingSecret: $client->signing_secret,
            devMode: (bool) $client->dev_mode,
        );
    }

    public function validateCallbackUrl(int $clientId, string $url): void
    {
        if (!$this->callbackUrlValidator->isSecure($url)) {
            throw new \App\Components\RequestPipeline\Exceptions\ValidationException(
                errorCode: 'INSECURE_CALLBACK_URL',
                message: 'Callback URL must use HTTPS.',
            );
        }

        if (!$this->callbackUrlValidator->validate($clientId, $url)) {
            throw new \App\Components\RequestPipeline\Exceptions\ValidationException(
                errorCode: 'CALLBACK_URL_NOT_ALLOWED',
                message: 'Callback URL is not in the allowed list for this client.',
                httpStatus: 403,
            );
        }
    }
}
