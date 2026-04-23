<?php

declare(strict_types=1);

namespace App\Components\Sessions\DTO;

use DateTimeImmutable;

final readonly class SessionCreateInput
{
    public function __construct(
        public int $clientId,
        public ?int $workspaceId,
        public string $modelAlias,
        public ?string $system,
        public array $tools,
        public ?string $cacheStrategy,
        public array $contextManagement,
        public bool $autoResume,
        public ?DateTimeImmutable $expiresAt,
        public ?array $mcpServers = null,
    ) {}
}
