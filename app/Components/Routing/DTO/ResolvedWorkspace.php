<?php

declare(strict_types=1);

namespace App\Components\Routing\DTO;

final readonly class ResolvedWorkspace
{
    public function __construct(
        public int $workspaceId,
        public string $name,
        public string $apiKey,
        public ?string $anthropicWorkspaceId,
    ) {}

    public function __debugInfo(): array
    {
        return [
            'workspaceId' => $this->workspaceId,
            'name' => $this->name,
            'anthropicWorkspaceId' => $this->anthropicWorkspaceId,
            'apiKey' => '***REDACTED***',
        ];
    }
}
