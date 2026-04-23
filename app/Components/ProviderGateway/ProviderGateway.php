<?php

namespace App\Components\ProviderGateway;

use App\Components\PromptAssembler\DTO\AssembledPayload;
use App\Components\ProviderGateway\Contracts\ProviderDriverContract;
use App\Components\ProviderGateway\DTO\RawProviderResponse;
use App\Components\ProviderGateway\DTO\ResolvedProvider;
use App\Components\ProviderGateway\Streaming\ProviderStreamReader;
use App\Components\RequestPipeline\DTO\ParsedRequest;
use Psr\Http\Message\ResponseInterface;

class ProviderGateway
{
    /** @param array<string, ProviderDriverContract> $drivers */
    public function __construct(
        private readonly ProviderResolver $resolver,
        private readonly FallbackExecutor $fallbackExecutor,
        private readonly array $drivers,
        private readonly ?ProviderStreamReader $streamReader = null,
    ) {}

    public function resolveProvider(ParsedRequest $parsed): ResolvedProvider
    {
        return $this->resolver->resolve($parsed->provider);
    }

    public function send(AssembledPayload $payload, ResolvedProvider $provider, int $timeoutSeconds = 300): RawProviderResponse
    {
        $driver = $this->getDriver($provider->providerName);

        return $driver->send($payload, $provider, $timeoutSeconds);
    }

    public function sendStreaming(AssembledPayload $payload, ResolvedProvider $provider, int $timeoutSeconds): ResponseInterface
    {
        $reader = $this->streamReader ?? new ProviderStreamReader();

        return $reader->sendStreaming($payload, $provider, $timeoutSeconds);
    }

    public function getFallbackExecutor(): FallbackExecutor
    {
        return $this->fallbackExecutor;
    }

    private function getDriver(string $providerName): ProviderDriverContract
    {
        return $this->drivers[$providerName]
            ?? throw new \RuntimeException("No driver registered for provider: $providerName");
    }
}
