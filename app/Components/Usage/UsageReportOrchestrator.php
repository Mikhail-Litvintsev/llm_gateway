<?php

declare(strict_types=1);

namespace App\Components\Usage;

use App\Components\Usage\DTO\UsageReportRequest;
use App\Models\Client;
use RuntimeException;

final readonly class UsageReportOrchestrator
{
    public function __construct(
        private UsageReportFetcher $fetcher,
    ) {}

    /** @return array<string, mixed> */
    public function getUsage(Client $client, array $queryParams): array
    {
        $allowedFeatures = $client->allowed_features ?? [];
        if (!($allowedFeatures['usage_api'] ?? false)) {
            throw new RuntimeException('usage_api_not_enabled', 403);
        }

        $workspaceId = $client->anthropic_workspace_id ?? null;
        if ($workspaceId === null) {
            throw new RuntimeException('anthropic_workspace_id_missing', 400);
        }

        $startingAt = $queryParams['starting_at'] ?? null;
        if ($startingAt === null) {
            throw new RuntimeException('starting_at is required', 400);
        }

        $request = new UsageReportRequest(
            startingAt: $startingAt,
            endingAt: $queryParams['ending_at'] ?? null,
            bucketWidth: $queryParams['bucket_width'] ?? '1d',
            workspaceId: $workspaceId,
            limit: isset($queryParams['limit']) ? (int) $queryParams['limit'] : null,
            page: $queryParams['page'] ?? null,
        );

        return $this->fetcher->fetch($request);
    }
}
