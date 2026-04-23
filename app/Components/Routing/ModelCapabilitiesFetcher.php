<?php

declare(strict_types=1);

namespace App\Components\Routing;

use App\Components\Routing\DTO\ModelCapabilities;
use Illuminate\Http\Client\Factory as HttpClient;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ModelCapabilitiesFetcher
{
    public function __construct(
        private HttpClient $http,
        private LoggerInterface $log,
    ) {}

    public function fetch(string $snapshot): ModelCapabilities
    {
        $response = $this->http
            ->withHeaders([
                'x-api-key' => config('llm.claude.default_api_key'),
                'anthropic-version' => config('llm.claude.anthropic_version'),
            ])
            ->timeout(10)
            ->get(config('llm.claude.endpoints.models') . '/' . $snapshot);

        $response->throw();

        return ModelCapabilities::fromApi($response->json());
    }

    public function fetchSafe(string $snapshot, ModelCapabilities $fallback): ModelCapabilities
    {
        try {
            return $this->fetch($snapshot);
        } catch (Throwable $e) {
            $this->log->warning('Failed to fetch model capabilities', [
                'snapshot' => $snapshot,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }
}
