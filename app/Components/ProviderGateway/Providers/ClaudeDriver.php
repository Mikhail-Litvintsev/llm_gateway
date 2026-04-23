<?php

namespace App\Components\ProviderGateway\Providers;

use App\Components\PromptAssembler\DTO\AssembledPayload;
use App\Components\ProviderGateway\Contracts\ProviderDriverContract;
use App\Components\ProviderGateway\DTO\RawProviderResponse;
use App\Components\ProviderGateway\DTO\ResolvedProvider;
use App\Components\ProviderGateway\Enums\ProviderName;
use Illuminate\Http\Client\Factory as HttpFactory;

class ClaudeDriver implements ProviderDriverContract
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function name(): ProviderName
    {
        return ProviderName::Claude;
    }

    public function send(AssembledPayload $payload, ResolvedProvider $provider, int $timeoutSeconds): RawProviderResponse
    {
        $startTime = microtime(true);

        try {
            $response = $this->http
                ->withHeaders(array_merge($payload->headers, [
                    'x-api-key' => $provider->apiKey,
                    'anthropic-version' => '2023-06-01',
                ]))
                ->timeout($timeoutSeconds)
                ->post($provider->endpoint, $payload->body);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            return new RawProviderResponse(
                httpStatus: $response->status(),
                body: $response->json() ?? [],
                headers: $response->headers(),
                durationMs: $durationMs,
            );
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            return new RawProviderResponse(
                httpStatus: 0,
                body: ['error' => ['type' => 'connection_error', 'message' => $e->getMessage()]],
                headers: [],
                durationMs: $durationMs,
            );
        }
    }
}
