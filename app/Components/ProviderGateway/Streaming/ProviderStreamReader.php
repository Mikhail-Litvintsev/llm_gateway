<?php

namespace App\Components\ProviderGateway\Streaming;

use App\Components\PromptAssembler\DTO\AssembledPayload;
use App\Components\ProviderGateway\DTO\ResolvedProvider;
use App\Components\ProviderGateway\Enums\ProviderName;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

class ProviderStreamReader
{
    private readonly Client $guzzle;

    public function __construct()
    {
        $this->guzzle = new Client();
    }

    /**
     * Отправляет streaming-запрос провайдеру и возвращает PSR-7 response с потоковым телом.
     */
    public function sendStreaming(
        AssembledPayload $payload,
        ResolvedProvider $provider,
        int $timeoutSeconds,
    ): ResponseInterface {
        $headers = array_merge($payload->headers, $this->getProviderHeaders($provider));
        $headers['Accept'] = 'text/event-stream';

        return $this->guzzle->post($provider->endpoint, [
            'json' => $payload->body,
            'headers' => $headers,
            'stream' => true,
            'timeout' => $timeoutSeconds,
            'connect_timeout' => 10,
        ]);
    }

    private function getProviderHeaders(ResolvedProvider $provider): array
    {
        $providerName = ProviderName::tryFrom($provider->providerName) ?? ProviderName::Claude;

        return match ($providerName) {
            ProviderName::Claude => [
                'x-api-key' => $provider->apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            ProviderName::OpenAi, ProviderName::DeepSeek, ProviderName::Mistral => [
                'Authorization' => "Bearer {$provider->apiKey}",
            ],
            ProviderName::Gemini => [
                'x-goog-api-key' => $provider->apiKey,
            ],
        };
    }
}
