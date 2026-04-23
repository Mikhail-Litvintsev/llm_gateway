<?php

declare(strict_types=1);

namespace App\Components\Usage\DTO;

final readonly class UsageReportRequest
{
    public function __construct(
        public string $startingAt,
        public ?string $endingAt,
        public string $bucketWidth,
        public string $workspaceId,
        public ?int $limit = null,
        public ?string $page = null,
    ) {}

    public function toQueryParams(): array
    {
        $params = [
            'starting_at' => $this->startingAt,
            'bucket_width' => $this->bucketWidth,
            'workspace_ids[]' => $this->workspaceId,
        ];

        if ($this->endingAt !== null) {
            $params['ending_at'] = $this->endingAt;
        }

        if ($this->limit !== null) {
            $params['limit'] = $this->limit;
        }

        if ($this->page !== null) {
            $params['page'] = $this->page;
        }

        return $params;
    }
}
