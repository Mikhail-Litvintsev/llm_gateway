<?php

declare(strict_types=1);

namespace App\Components\Usage;

use App\Components\Usage\DTO\UsageReportRequest;
use Illuminate\Http\Client\Factory as HttpClient;
use RuntimeException;

final readonly class UsageReportFetcher
{
    public function __construct(
        private HttpClient $http,
        private string $adminApiKey,
        private string $baseUrl,
    ) {}

    /** @return array<string, mixed> */
    public function fetch(UsageReportRequest $request): array
    {
        $response = $this->http
            ->withHeaders([
                'x-api-key' => $this->adminApiKey,
                'anthropic-version' => config('llm.claude.anthropic_version'),
            ])
            ->timeout(30)
            ->get($this->baseUrl, $request->toQueryParams());

        if ($response->status() === 429) {
            throw new RuntimeException('upstream_rate_limited', 502);
        }

        $response->throw();

        return $response->json();
    }
}
